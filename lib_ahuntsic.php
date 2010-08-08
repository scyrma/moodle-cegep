<?php

function cegep_ahuntsic_sisdbsource_select_students($term) {
return <<< EOD
    DECLARE @AnSession_IN smallint;
    SET @AnSession_IN = $term;
    SELECT
        uo.Numero AS CourseUnit
        ,c.Numero AS CourseNumber
        ,c.TitreMoyen AS CourseTitle
        ,g.Numero AS CourseGroup
        ,e.Numero AS StudentNumber
        ,e.Nom AS StudentLastName
        ,e.Prenom AS StudentFirstName
        ,es.AnSession AS CourseTerm
        ,p.Numero AS StudentProgram
        ,CEILING(CAST(es.SPE AS FLOAT)/2) AS StudentProgramYear
        ,p.TitreLong AS StudentProgramName
    FROM
        Etudiants.Etudiant e
        JOIN Etudiants.EtudiantSession es ON es.IDEtudiant = e.IDEtudiant
        JOIN Inscriptions.Inscription i ON i.IDEtudiantSession = es.IDEtudiantSession
        JOIN Groupes.Groupe g ON g.IDGroupe = i.IDGroupe
        JOIN BanqueCours.Cours c ON c.IDCours = i.IDCours
        JOIN Programmes.Programme p on p.IDProgramme = es.IDProgramme
        JOIN Reference.UniteOrg uo ON uo.IDUniteOrg = i.IDUniteOrg
    WHERE
        es.Etat > 0
        AND i.Etat > 0
        AND uo.IndicateurLocal = 1
        AND es.AnSession >= @AnSession_IN
    ORDER BY
        e.Numero, c.Numero;
EOD;
}

function cegep_ahuntsic_sisdbsource_select_teachers($term) {
return <<< EOD
    DECLARE @AnSession_IN smallint;
    SET @AnSession_IN = $term;
    SELECT
        g.AnSession CourseTerm,
        e.Numero TeacherNumber,
        c.Numero CourseNumber, 
        g.Numero CourseGroup,
        c.TitreMoyen AS CourseTitle
    FROM
        Employes.Employe e
        JOIN Groupes.EmployeGroupe ge ON e.IDEmploye = ge.IDEmploye
        JOIN Groupes.Groupe g ON g.IDGroupe = ge.IDGroupe
        JOIN BanqueCours.Cours c ON g.IDCours = c.IDCours
    WHERE
        g.AnSession >= @AnSession_IN
    ORDER BY
        g.AnSession, e.Numero, c.Numero, g.Numero;
EOD;
}

function cegep_ahuntsic_sisdbsource_decode($field, $data) {
    switch ($field) {

    case 'studentnumber':
        // Replace two leading numbers by 'e'
        return 'e' . substr($data, 2);
        break;

    case 'coursenumber':
        // Remove hyphens
        return str_replace('-', '', $data);
        break;

    case 'coursegroup':
        // Remove hyphens
        return str_pad($data, 6, '0', STR_PAD_LEFT);
        break;

    case 'courseterm':
        // Break into array of year and semester
        return array('year' => substr($data, 0, 4), 'semester' => substr($data, 4, 1));
        break;

    case 'coursetitle':
        return $data;

    case 'program':
        // Remove hyphens
        return str_replace('.', '', $data);
        break;

    case 'studentlastname':
    case 'studentfirstname':
	return utf8_encode($data);

    case 'studentprogramname':
        return utf8_encode($data);

    default:
        // Do nothing
        return $data;
        break;
    }
    
}



function cegep_ahuntsic_course_category($category_code) {
  switch ($category_code) {
    case ('401') :
      $category = 147; // Administration ok
      break;

    case ('609') :
      $category = 52; // Allemand ok
      break;

    case ('604') :
      $category = 110; // Anglais (langue seconde) ok
      break;

    case ('381') :
      $category = 148; // Anthropologie ok
      break;

    case ('411') :
      $category = 149; // Archives médicales ok
      break;

    case ('504') :
      $category = 150; // Art et esthétique ok
      break;

    case ('570') :
      $category = 151; // Arts appliqués ok
      break;

    case ('502') :
      $category = 86; // Arts et lettres ok
      break;

    case ('510') :
      $category = 152; // Arts plastiques ok
      break;

    case ('101') :
      $category = 49; // Biologie ok
      break;

    case ('202') :
      $category = 88; // Chimie ok
      break;

    case ('530') :
      $category = 61; // Cinéma ok
      break;

    case ('058') :
    case ('581') :
      $category = 40; // *Communications graphiques ok
      break;

    case ('105') :
      $category = 153; // Culture scientifique et technologique ok
      break;

    case ('242') :
      $category = 154; // Dessin technique ok
      break;

    case ('383') :
      $category = 155; // Économique ok
      break;

    case ('109') :
      $category = 107; // Éducation physique ok
      break;

    case ('130') :
      $category = 156; // Électrophysiologie médicale ok
      break;

    case ('607') :
      $category = 53; // Espagnol ok
      break;

    case ('520') :
      $category = 157; // Esthétique et histoire de l'art ok
      break;

    case ('060') :
    case ('601') :
      $category = 109; // *Français (langue et littérature) ok
      break;

    case ('602') :
      $category = 158; // Français (langue seconde) ok
      break;

    case ('320') :
      $category = 159; // Géographie ok
      break;

    case ('205') :
      $category = 160; // Géologie ok
      break;

    case ('330') :
      $category = 161; // Histoire ok
      break;

    case ('204') :
      $category = 162; // Langage mathématique et informatique ok
      break;
     
    case ('201') :
      $category = 87; // Mathématique ok
      break;   
     
    case ('036') :
    case ('360') :
      $category = 163; // *Multidisciplinaire ok
      break;   

    case ('000') :
    case ('020') :
    case ('022') :
    case ('030') :
    case ('031') :
    case ('954') :
    case ('982') :
    case ('COM') :
    case ('EUL') :
    case ('SPU') :
      $category = 0; // *Ne s'applique pas
      break;     

    case ('340') :
      $category = 108; // Philosophie ok
      break;

    case ('203') :
      $category = 89; // Physique ok
      break;
     
    case ('235') :
      $category = 165; // Production industrielle ok
      break;
     
    case ('350') :
      $category = 168; // Psychologie ok
      break;
     
    case ('385') :
      $category = 170; // Science politique ok
      break;
     
    case ('300') :
      $category = 36; // Sciences humaines ok
      break;     
     
    case ('305') :
      $category = 171; // Sciences humaines (complémentaire) ok
      break;
     
    case ('311') :
      $category = 172; // Sécurité incendie ok
      break;
     
    case ('387') :
      $category = 173; // Sociologie ok
      break;
     
    case ('181') :
      $category = 174; // Soins préhospitaliers d'urgence ok
      break;
     
    case ('410') :
      $category = 30; // Techniques administratives ok
      break;     

    case ('310') :
      $category = 176; // Techniques auxiliaires de la justice ok
      break;
     
    case ('222') :
      $category = 178; // Techniques d'aménagement et d'urbanisme ok
      break;
     
    case ('412') :
      $category = 180; // Techniques de bureautique ok
      break;
     
    case ('210') :
      $category = 181; // Techniques de chimie industrielle ok
      break;
     
    case ('241') :
      $category = 179; // Techniques de la mécanique ok
      break;
     
    case ('107') :
      $category = 8; // Techniques de la santé ok
      break;
     
    case ('042') :
    case ('420') :
      $category = 177; // *Techniques de l'informatique ok
      break;
     
    case ('211') :
      $category = 175; // Techniques de matières plastiques ok
      break;
     
    case ('142') :
      $category = 999; // Techniques de radiologie DOUBLON ????
      break;
     
    case ('230') :
      $category = 169; // Techniques de radiologie ok DOUBLON ???
      break;     
   
    case ('022') :
    case ('221') :
      $category = 167; // *Technologie du bâtiment et des travaux publics ok
      break;
     
    case ('243') :
      $category = 166; // Technologie du génie électrique ok
      break;
     
    case ('270') :
      $category = 164; // Technologie du génie métallurgique ok
      break;
     
    case ('244') :
      $category = 38; // Technologie physique ok
      break;     

    default:
      $category = 1; // misc, catch-all
  }

  return $category;
}

