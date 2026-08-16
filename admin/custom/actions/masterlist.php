<?php
/*
 * Custom Action: masterlist
 * ------------------------------------------------------------
 * Move the body of case "masterlist": from the old custom.php here.
 */
 $data = $_POST;
    $filename = $_FILES["file"]["tmp_name"];
			if($_FILES["file"]["size"] > 0){
				$csv = $m->csvToArray($_FILES["file"]["tmp_name"]);
			 }

		$userarray = array();
		$csvuser = array();
		$userarray = array();

		//echo json_encode($csv)."</br>";

		foreach($csv as $items) {

			foreach($items as $key => $value) {
				if ($value == null) continue;
				
				if ($key == 'Student_Number')
					$userarray['employeeid'] = $value;
				if ($key == 'SchoolID')
					$userarray['localeid'] = $value;
				
			}
				$csvuser[] = $userarray;
		}
$response .= "
<h1>Accounts listed to be deleted</h1>
<table id='fresh-table' class='table'>
   <thead>
  <tr>
    <th data-field='id'>#</th>
    <th data-field='StudentID'>StudentID</th>
    <th data-field='Username'>Username</th>
    <th data-field='status'>Status</th>
  </tr>
     </thead>";		
 
 $i = 1;
$masterldap = _getad('stu.k12.vi');
$fields = array('samaccountname', 'employeeid', 'mail',"localeid");
$filter = '(&(objectCategory=person)(objectClass=user)(|(mail=*stu.vide.vi)))';
$find = _getusers($masterldap->user()->all($filter, $fields, '',true, true));

		foreach ($find as $key => $found){
			if (contains($found['dn'], "OU=Disabled")) continue;
				if (contains($key, "count"))
					continue;
				$checkid[$found['employeeid']] = $found;
		}
$user;
$csvid;
foreach($csvuser as $csv){
	$csvid[$csv['employeeid']] = [$csv];
}

foreach ($checkid as $items){
$masterarray->user = (object) $items;
$csvkey = $csvid[$masterarray->user->employeeid];
		if (isset($csvkey)){
			continue;
		}

		$result;
		if ($data["mastercreate"] == "1") {
				try{
						$moveUser = $masterldap->user()->move($masterarray->user->samaccountname, array('Disabled Users'));
					if ($moveUser) {
						$result .= "MOVED:".json_encode($masterldap->getLastError());
						
						$masterldap->user()->modify($masterarray->user->samaccountname, array("useraccountcontrol" => 514));
						foreach ($masterldap->user()->groups($masterarray->user->samaccountname) as $group){ //remove current WAP
							if (contains($group, "stuwap"))
								$masterldap->group()->removeUser($group, $masterarray->user->samaccountname);
						}
						$removegrp = $masterldap->group()->removeUser("students", $masterarray->user->samaccountname);
						
						if ($removegrp)
							$result .= " Group:".json_encode($masterldap->getLastError());
						
					} else {
						$result = "ERROR:".json_encode($masterldap->getLastError());
					}
				}
					catch (adLDAPException $e) {
					$result = "ERROR:".$e;	
				}
					} else {		
					$result = "Test Mode";
			}	

  $response.= "<tr>
    <td>". $i."</td>
    <td>". $masterarray->user->employeeid."</td>
    <td>". $masterarray->user->samaccountname."</td>
    <td>". $result ."</td>";
	
	$i++;
}
 $response .= "</tbody>
  </table>";
  
	echo json_encode(array(
        "info" => ($response),	
        "result" => 0
		));