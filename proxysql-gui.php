<?php 

// MySQL-Verbindungsdaten
$hostname = '127.0.0.1';
$username = 'radmin';
$password = 'xxx';
$database = '';


// Verbindung zur MySQL-Datenbank herstellen
$connection = new mysqli($hostname, $username, $password, $database,'6032');

function genTable(Array $dataArray){

    echo '<table  class="table is-bordered is-striped">';
    echo '<thead><tr>';
    
    // Überschriften aus den Schlüsseln des ersten Datensatzes generieren
    foreach ($dataArray[0] as $key => $value) {
        echo '<th>' . $key . '</th>'."\n";
    }
    
    echo '</tr></thead><tbody>';

    // Datenzeilen erstellen
    foreach ($dataArray as $data) {
        echo '<tr>';

        // Werte in den Datenzeilen ausgeben
        foreach ($data as $value) {
            echo '<td>' . $value . '</td>'."\n";
        }

        echo '</tr>'."\n"."\n";
    }

    echo '</tbody></table>';
 }
?>
    <link rel="stylesheet" href="/crm/gui/vendor/bulma/css/bulma.css">
<style>body{overflow: scroll; } </style>
<?php



// Überprüfen, ob die Verbindung erfolgreich war
if ($connection->connect_error) {
    die('Verbindung fehlgeschlagen: ' . $connection->connect_error);
}

foreach(array('mysql_servers','mysql_query_rules','runtime_mysql_query_rules') as $tbl){
	$query = "SELECT * FROM $tbl";
	$result = $connection->query($query);
	$rows = $result->fetch_all(MYSQLI_ASSOC);
	echo '<h2 class="title is-2">'.$tbl.'</h2>';
	echo genTable($rows);

}



	$query = "select rule_id,digest,match_digest,match_pattern,log,hits from runtime_mysql_query_rules 
left join stats_mysql_query_rules using (rule_id)
";
	$result = $connection->query($query);
	$rows = $result->fetch_all(MYSQLI_ASSOC);
	echo '<h2 class="title is-2">HITS  Query_Cache</h2>';
	echo genTable($rows);
	
	
	$query = "SELECT * FROM stats_mysql_global WHERE Variable_Name LIKE 'Query_Cache%';";
	$result = $connection->query($query);
	$rows = $result->fetch_all(MYSQLI_ASSOC);
	echo '<h2 class="title is-2">stats_mysql_global WHERE Variable_Name LIKE Query_Cache%</h2>';
	echo genTable($rows);

	$query = "SELECT count_star,sum_time,hostgroup,digest,digest_text FROM stats_mysql_query_digest_reset ORDER BY sum_time DESC;";
	$result = $connection->query($query);
	$rows = $result->fetch_all(MYSQLI_ASSOC);
	echo '<h2 class="title is-2">stats_mysql_query_digest_reset</h2>';
	echo genTable($rows);

