<?php
namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\{JsonResponse,RedirectResponse,Request,Response};
use Symfony\Component\Routing\Attribute\Route;

final class AppController extends AbstractController
{
    private function user(Request $r): ?array { return $r->getSession()->get('user'); }
    private function guard(Request $r): ?Response { return $this->user($r) ? null : new RedirectResponse($r->getBaseUrl().'/login'); }

    #[Route('/install', name:'install')]
    public function install(Request $r, Connection $db): Response
    {
        if(!hash_equals((string)($_ENV['INSTALL_TOKEN']??''),(string)$r->query->get('token'))) throw $this->createAccessDeniedException();
        $sql = file_get_contents($this->getParameter('kernel.project_dir').'/data/schema.sql');
        foreach (array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $sql))) as $q) $db->executeStatement($q);
        if (!(int)$db->fetchOne('SELECT COUNT(*) FROM users')) {
            $db->insert('users',['email'=>'enseignant@capcm1.local','name'=>'Enseignant CM1','password_hash'=>'$2y$10$PZoyWYq/7cdo9cW5ZLgjAOalxU0lCDxrlHCUVU9NAO5yFh6bX/4ra','created_at'=>date('Y-m-d H:i:s')]);
        }
        if (!(int)$db->fetchOne('SELECT COUNT(*) FROM competencies')) {
            $data=json_decode(file_get_contents($this->getParameter('kernel.project_dir').'/data/referentiel.json'),true);
            foreach($data['competences'] as $c) $db->insert('competencies',[
                'code'=>$c['id'],'subject'=>$c['matiere'],'domain'=>$c['domaine'],'period'=>$c['periode_introduction'],
                'reactivation'=>$c['periodes_reactivation'],'competency'=>$c['competence_bo'],'objective'=>$c['objectif_pedagogique'],
                'success_criteria'=>$c['critere_reussite'],'support'=>$c['support_methode']
            ]);
        }
        return new Response('<h1>Mon Atelier de Classe est installé</h1><p><a href="/login">Se connecter</a></p><p>Identifiant : enseignant@capcm1.local<br>Mot de passe temporaire : Changez-moi-2026!</p>');
    }

    #[Route('/login', name:'login', methods:['GET','POST'])]
    public function login(Request $r, Connection $db): Response
    {
        $error='';
        if($r->isMethod('POST')){
            $u=$db->fetchAssociative('SELECT * FROM users WHERE email=?',[$r->request->get('email')]);
            if($u && password_verify((string)$r->request->get('password'),$u['password_hash'])){
                $r->getSession()->migrate(true); $r->getSession()->set('user',['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email']]);
                return new RedirectResponse($r->getBaseUrl().'/');
            } $error='Identifiant ou mot de passe incorrect.';
        }
        return $this->render('login.html.twig',['error'=>$error]);
    }

    #[Route('/logout', name:'logout')]
    public function logout(Request $r): Response { $base=$r->getBaseUrl(); $r->getSession()->invalidate(); return new RedirectResponse($base.'/login'); }

    #[Route('/', name:'home')]
    public function home(Request $r, Connection $db): Response
    {
        if($g=$this->guard($r)) return $g;
        $stats=['competencies'=>(int)$db->fetchOne('SELECT COUNT(*) FROM competencies'),'preps'=>(int)$db->fetchOne('SELECT COUNT(*) FROM prep_sheets'),'sequences'=>(int)$db->fetchOne('SELECT COUNT(*) FROM sequences'),'journal'=>(int)$db->fetchOne('SELECT COUNT(*) FROM journal_entries')];
        $token=bin2hex(random_bytes(24)); $r->getSession()->set('csrf',$token);
        return $this->render('app.html.twig',['user'=>$this->user($r),'stats'=>$stats,'csrf'=>$token]);
    }

    #[Route('/api/{resource}', name:'api', methods:['GET','POST','PUT','DELETE'])]
    public function api(string $resource, Request $r, Connection $db): JsonResponse
    {
        if(!$this->user($r)) return $this->json(['error'=>'Non authentifié'],401);
        $map=['competencies'=>'competencies','preps'=>'prep_sheets','sequences'=>'sequences','journal'=>'journal_entries','settings'=>'class_settings'];
        if(!isset($map[$resource])) return $this->json(['error'=>'Ressource inconnue'],404); $table=$map[$resource];
        if($r->isMethod('GET')){
            $sql="SELECT * FROM $table"; $args=[];
            if($resource==='competencies'){$sql.=' WHERE 1=1';foreach(['subject','period','domain'] as $f)if($r->query->get($f)){ $sql.=" AND $f=?";$args[]=$r->query->get($f);}if($q=$r->query->get('q')){$sql.=' AND (competency LIKE ? OR objective LIKE ? OR code LIKE ?)';$args=array_merge($args,["%$q%","%$q%","%$q%"]);}}
            $sql.=$resource==='journal'?' ORDER BY entry_date,start_time':($resource==='competencies'?' ORDER BY subject,domain,code':' ORDER BY updated_at DESC');
            return $this->json($db->fetchAllAssociative($sql,$args));
        }
        if(!hash_equals((string)$r->getSession()->get('csrf'),(string)$r->headers->get('X-CSRF-TOKEN'))) return $this->json(['error'=>'Jeton invalide'],403);
        $p=json_decode($r->getContent(),true) ?: []; $id=(int)($p['id']??0);
        if($r->isMethod('DELETE')){$db->delete($table,['id'=>$id]);return $this->json(['ok'=>true]);}
        $allowed=[
            'preps'=>['name','competency_id','objective','duration','materials','steps','differentiation','assessment','notes'],
            'sequences'=>['name','subject','period','description','competency_ids','session_plan','assessment'],
            'journal'=>['entry_date','start_time','end_time','subject','competency_id','prep_id','sequence_id','title','objective','activity','materials','differentiation','assessment','notes'],
            'settings'=>['school','teacher','class_name','student_count','school_year','week_days','day_start','day_end','breaks','location'],
        ]; if(!isset($allowed[$resource])) return $this->json(['error'=>'Lecture seule'],405);
        $data=[];foreach($allowed[$resource] as $f)if(array_key_exists($f,$p))$data[$f]=is_array($p[$f])?json_encode($p[$f],JSON_UNESCAPED_UNICODE):$p[$f];
        if($resource==='journal' && !empty($data['competency_id'])) $data['subject']=(string)$db->fetchOne('SELECT subject FROM competencies WHERE id=?',[$data['competency_id']]);
        $data['updated_at']=date('Y-m-d H:i:s');
        if($id){$db->update($table,$data,['id'=>$id]);}else{$data['created_at']=date('Y-m-d H:i:s');$db->insert($table,$data);$id=(int)$db->lastInsertId();}
        return $this->json(['ok'=>true,'id'=>$id]);
    }

    #[Route('/account', name:'account', methods:['POST'])]
    public function account(Request $r, Connection $db): Response
    {
        if($g=$this->guard($r)) return $g;
        if(!hash_equals((string)$r->getSession()->get('csrf'),(string)$r->request->get('_token'))) throw $this->createAccessDeniedException();
        $p=(string)$r->request->get('password'); if(strlen($p)>=10)$db->update('users',['password_hash'=>password_hash($p,PASSWORD_DEFAULT),'name'=>(string)$r->request->get('name')],['id'=>$this->user($r)['id']]);
        return new RedirectResponse($r->getBaseUrl().'/');
    }

    #[Route('/backup', name:'backup')]
    public function backup(Request $r, Connection $db): JsonResponse
    {
        if(!$this->user($r)) return $this->json(['error'=>'Non authentifié'],401);
        $out=['version'=>1,'exported_at'=>date(DATE_ATOM)];foreach(['class_settings','prep_sheets','sequences','journal_entries'] as $t)$out[$t]=$db->fetchAllAssociative("SELECT * FROM $t");
        $res=$this->json($out);$res->headers->set('Content-Disposition','attachment; filename="mon-atelier-de-classe-sauvegarde-'.date('Y-m-d').'.json"');return $res;
    }

    #[Route('/restore', name:'restore', methods:['POST'])]
    public function restore(Request $r, Connection $db): Response
    {
        if($g=$this->guard($r)) return $g;
        if(!hash_equals((string)$r->getSession()->get('csrf'),(string)$r->request->get('_token'))) throw $this->createAccessDeniedException();
        $file=$r->files->get('backup'); if(!$file || $file->getSize()>10_000_000) return new Response('Fichier invalide',400);
        $data=json_decode(file_get_contents($file->getPathname()),true); if(!is_array($data)||($data['version']??null)!==1) return new Response('Sauvegarde incompatible',400);
        $allowed=['class_settings','prep_sheets','sequences','journal_entries'];$db->beginTransaction();
        try{foreach(array_reverse($allowed) as $t)$db->executeStatement("DELETE FROM $t");foreach($allowed as $t)foreach(($data[$t]??[]) as $row){unset($row['id']);$db->insert($t,$row);}$db->commit();}catch(\Throwable $e){$db->rollBack();return new Response('Restauration impossible : '.$e->getMessage(),400);}
        return new RedirectResponse($r->getBaseUrl().'/');
    }
}
