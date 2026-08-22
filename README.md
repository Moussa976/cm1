# Mon Atelier de Classe

Application personnelle de pilotage pédagogique pour une classe de CM1 : référentiel BO, programmation, cahier journal, fiches de préparation, séquences, paramètres de classe, exports et sauvegardes.

## Accès local de développement

`php -S 127.0.0.1:8091 -t public`

Puis ouvrir `http://127.0.0.1:8091/login`.

## Données

Les 124 compétences-objectifs sont importées depuis `data/referentiel.json`. Les données personnelles sont enregistrées dans MySQL et ne dépendent plus du stockage du navigateur.
