<?php
/*
 * Custom Action: sort
 * ------------------------------------------------------------
 * Move the body of case "sort": from the old custom.php here.

 */
$data = $_POST;
    $filename = $_FILES["file"]["tmp_name"];
			if($_FILES["file"]["size"] > 0){
				$csv = $m->csvToArray($_FILES["file"]["tmp_name"]);
			 }

	$find = array();
	$checkid = array();
	$userids = array();
	
	$sortldap = _getad('stu.k12.vi');

		$fields = array('samaccountname', 'mail', 'employeeid', 'localeid');
			$filter = '(&(objectCategory=person)(objectClass=user)(|(mail=*stu.vide.vi)))';
			
		$find = _getusers($sortldap->user()->all($filter, $fields, '',true, true));

		foreach ($find as $key => $found){
		if (contain($key, "count")) continue;
			if (!empty($found['employeeid'][0])) {
				$checkid[$found['employeeid']] = $found;
			}
		}
		
$response .= "
<table id='fresh-table' class='table'>
   <thead>
  <tr>
    <th data-field='id'>#</th>
    <th data-field='userid'>UserID</th>
    <th data-field='studentid'>StudentID</th>
    <th data-field='location'>Location</th>
    <th data-field='moved'>Moved</th>
	<th data-field='status'>Status</th>
  </tr>
     </thead>";	
$ou = array();

//Find all Folders for Location information.
$folders = _getfolder($sortldap->folder()->listing(NULL, adLDAP::ADLDAP_FOLDER, true, 'folder'));
foreach ($folders as $key => $folder){
	if (!empty($folder['flags']))
		$ou[$folder['flags']] = array('dn' => $folder['dn'], 'name' => $folder['admindescription']);
}

$localeid = array(
109	=> "Bertha C. Boschulte Middle School",
111 => "Leonard Dober Elementary School",
112 => "Joseph Sibilly Elementary School",
115 => "Lockhart Elementary School",
117 => "Ulla F. Muller Elementary School",
118 => "Jane E. Tuitt Elementary School",
120 => "Joseph Gomez Elementary School",
121 => "Gladys A. Abraham Elementary School",
123 => "Yvonne E. Milliner-Bowsky Elementary School",
125 => "Addelita Cancryn Junior High School",
126 => "E. Benjamin Oliver Elementary School",
127 => "Charlotte Amalie High School",
128 => "Ivanna Eudora Kean High School",
152 => "Julius E. Sprauve School",
230 => "Juanita Gardine Elementary School",
232 => "Eulalie Rivera Elementary School",
233 => "Lew Muckle Elementary School",
234 => "Claude O. Markoe Elementary School",
235 => "Alexander Henderson Elementary School",
236 => "Pearl B. Larsen Elementary School",
237 => "Alfredo Andrews Elementary School",
243 => "Ricardo Richards Elementary School",
244 => "John H. Woodson Junior High School",
246 => "Arthur A. Richards Junior High School",
247 => "St. Croix Central High School",
248 => "St. Croix Educational Complex High School",
250 => "Elena L. Christian Junior High School",
8000=> "Raphael O Wheatley Skill Center",

6001 => "Antilles School",
6002 => "Bethel Baptist",
6003 => "Clavary Church",
6004 => "Faith Alive Christian Academy",
6005 => "Free Will Baptist Christian School",
6006 => "Gifft Hill School",
6007 => "Good Hope Country Day School",
6008 => "Good Hope Country Day School",
6009 => "School of the Good Shepherd, Inc",
6010 => "Seventh Day Adventist School",
6011 => "SJCA",
6012 => "St. Croix Seventh Day Adventist School",
6013 => "Coral Reef Academy",
6014 => "St Croix Christian Academy",
6015 => "St. Mary Catholic School",
6016 => "St. Patrick Catholic School",
6017 => "St.Peter & Paul School",
6018 => "STX Montessori",
6019 => "VI MSIA",
6020 => "Wesleyan Academy",

7003 => "St. Croix Adult Continuing Education",
80000 => "STX",
90000 => "STTJ"
);

//CSV Values
	
	$combinedarray = array();
foreach($csv as $ckey => $items) {
	$userarray = array();
	$kid = array_values(preg_grep_keys ('/^(LOCAL_STUDENT_ID)$|^(STUDENT_ID)$/i', (array) $items))[0];

	foreach($items as $key => $value){
		if ($value == null)
			continue;

        if (contains($key, "SchoolID") || contains($key, "SCHOOL_ID")) {
            $userarray['localeid'] = $value;
        }
		elseif (contains($key, "Student_Number") || contains($key, "Student#") || contains($key, "Local_Student_Id")) {
			$userarray['employeeid'] = $value;
        }
        elseif (contains($key, "Grade_Level")) {
			$userarray['gidnumber'] = ($value);
        } else {
			//Do not care about other values
            $userarray[$key] = $value;
        }
	}
		$combinedarray[$ckey] = $userarray;
	}
	
	$i = 0;
	foreach($combinedarray as $infoarr) {
		$sortarr = $checkid[$infoarr['employeeid']];
		$sortarr['moved'] = $infoarr['localeid'];
		
		//if (contains($infoarr['dn'], "OU=Expired")) continue;
		$wap;
		//$udn = preg_replace("#(^CN=[a-zA-Z0-9.`'?e\xE8\xE9\xEA\xEB\-\s‚,]+,)#", '', $infoarr['dn']);
		$newoffice = $localeid[$sortarr['moved']];
		
		//if ($udn == $ou[$mkey]['dn']) continue;
		$lid = $infoarr['localeid'];
		if (empty($ou[$lid])) continue;
		//$ou = $ou[$lid]['dn'];
		//echo json_encode($lid)."</br>";
	
			//Update user Office and Descriptions 
			if ($infoarr['office'] !== $newoffice || $infoarr['description'] !== $newoffice || $infoarr['physicalDeliveryOfficeName'] !== $newoffice) {	
					$offices = array(
					'department' => $newoffice,
					'localeid' => $infoarr['localeid'],
					'description' => array($newoffice),
					'physicalDeliveryOfficeName' => $newoffice
					);
					
			}	
	if ($data["sortcreate"] == "1") {		
		try {
			$updated = $sortldap->user()->modify($sortarr['samaccountname'], $offices);
		}
		catch (adLDAPException $e) {
		$result = "ERROR: ".$e;
		error_log($result . ":" . json_encode($sortarr));
		}
		
	if ($updated) {	
		$groups = $sortldap->user()->groups($sortarr['samaccountname']);
		$wap = $ou[$lid]['name'].'StuWAP';	
			//Remove all other previous groups
			foreach ($groups as $group) {
				if (contains($group, "WAP"))
					if ($group !== $wap)
					$sortldap->group()->removeUser($group, $sortarr['samaccountname']);		
			}
				try{
				
				$ugrp = $sortldap->group()->addUser($ou[$lid]['name'].'StuWAP', $sortarr['samaccountname']);
				
				if ($ugrp) {
					$wap = "Msg: " . json_encode($sortldap->getLastError()) .  " Group Joined";		
				}
				
				$moveUser = $sortldap->user()->move($sortarr['samaccountname'], array_reverse(parseLdapDn($ou[$lid]['dn'],false)['ou']));
					if ($moveUser) {
						$result = "UPDATED:".json_encode($sortldap->getLastError());
					} else {
						$result = "ERROR:".json_encode($sortldap->getLastError());
					}
				}
					catch (adLDAPException $e) {
					$result = "ERROR:".$e;	
				}
	}
	}
  $response.= "<tr>
    <td>". $i."</td>
    <td>". $sortarr['samaccountname']."</td>
    <td>". $sortarr['employeeid']."</td>
    <td>". $localeid[$sortarr['localeid']]."</td>
    <td>". $localeid[$sortarr['moved']]."</td>
    <td>". $result ."</td>";
	
	$i++;
}
 $response .= "</tbody>
  </table>";
  
	echo json_encode(array(
        "info" => ($response),	
        "result" => 0
		));
