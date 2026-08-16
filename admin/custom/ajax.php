<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/env.php');
global $g;

//Set Vars Environment
$vars = (object) $_REQUEST;
$action = $vars->action;
	unset($vars->action);

switch ($action) {


    //**************
    //**************
    //Createuser
    //*************
    //*************
case 'createuser':

		$vars->password = $g->generate();

		//Check container.
			if (empty($vars->container)){
				echo json_encode(array(
				"info" => "Organizational Unit is empty",	
				"post" => $_POST,	
				"result" => 1
				));
				return;
			}
		$container = array_reverse(parseLdapDn($vars->container, false)['ou']);
		
		$createarray['username'] = trim($vars->samaccountname);
		$createarray['user_principalname'] = $vars->samaccountname.'@'.$vars->suffix;
		$createarray['firstname'] = trim($vars->givenname);
		$createarray['surname'] = trim($vars->sn);
		$createarray['department'] = trim($vars->department);
		$createarray['company'] = trim($vars->company);
		$createarray['email'] = $createarray['user_principalname'];		
		$createarray['container'] = $container;
		$createarray['enabled'] = 1;
		$createarray['password'] = $vars->password;
		
		//error_log("create-array: ".json_encode($createarray));
		//Only create users that do not currently exist.
		if (!empty($checkid[$vars->employeeid])) break; // continue;
		try {			
		$createldap = _getad($vars->container);		
		$create = $createldap->user()->create($createarray);		
		

		
		if ($create){
			$m->info(
				'createuser',
				$username . " created user '".$vars->samaccountname."'"
			);
				
				if("@".$vars->suffix == "@".$createldap->account_suffix){
						$proxyaddresses = array("SMTP:".$createarray['user_principalname'],"smtp:".$vars->targetaddress);
					} else {
						$proxyaddresses = array("SMTP:".$createarray['user_principalname'],"smtp:".$vars->targetaddress);
				}	
			
				//Update user info after creation
			$update = $createldap->user()->modify($createarray['username'], array(
				'employeeType' => 'staff',
				'middlename' => $vars->middlename,
				'initials' => $vars->initials,
				'employeeid' => $vars->employeeid,
				'postalcode' => $vars->postalcode,
				'description' => trim($vars->description),
				'physicaldeliveryofficename' => trim($vars->physicaldeliveryofficename),
				'title' => $vars->title,
				'description' => $vars->title,
				'targetaddress' => "SMTP:".$vars->targetaddress,
				'telephonenumber' => $vars->telephonenumber,
				'manager' => $createldap->user()->info($vars->manager)[0]['dn'],
				'proxyaddresses' => $proxyaddresses,
				'userprincipalname' => $createarray['user_principalname'],
				'pwdLastSet' => '000',
				'lockoutTime' => array(0),
				));

			$setpassword = $createldap->user()->password($vars->samaccountname, $vars->password);
			
			foreach($vars->groups as $group) 
				$createldap->group()->addUser($group, $createarray['username']);
			
			_updateldapcache(); //Update cache after successful user creation
		} else {
		$m->error(
			'createuser',
			$username . " failed to create user '". $vars->samaccountname ."': ". $createldap->getLastError()
		);
		//legacy_log_writer('createuser', 'debug', $username . " failed (". json_encode($createarray .") ");
		
		echo json_encode(array(
        "info" => json_encode("Failed to create (" . $vars->samaccountname . ") error: " . ($createldap->getLastError())),	
        //"info" => json_encode("Failed to create (" . $vars->samaccountname . ") error: " . ($createldap->getLastError()) . "<br>debug: " . json_encode($createarray)),	
        "result" => 1
		));
		return;
		}
	} catch (adLDAPException $e) {
		echo json_encode(array(
        "info" => json_encode($e),	
        "result" => 1
		));
		$m->error(
			'createuser',
			$username . " failed to create user '". $vars->samaccountname ."': ". $e->getMessage()
		);
		}
	echo json_encode(array(
        "create" => $createarray['username'],	
        "update" => json_encode($update),
        "password" => $vars->password,
        "result" => 0
		));
break;

    //**************
    //**************
    //Importusers
    //*************
    //*************
    case "importusers":
 require_once(DOCROOT.'/class/class.nameparser.php');
$data = $_POST;
    $filename = $_FILES["file"]["tmp_name"];
			if($_FILES["file"]["size"] > 0){
				$csv = $m->csvToArray($_FILES["file"]["tmp_name"]);
			 }
			 
	$find = array();
	$checkid = array();
	$userids = array();
	
	$importldap = _getad('stu.k12.vi');

		$fields = array('samaccountname', 'mail', 'employeeid');
			$filter = '(&(objectCategory=person)(objectClass=user)(|(mail=*stu.vide.vi)))';
			
		$find = _getusers($importldap->user()->all($filter, $fields, '',true, true));

		foreach ($find as $key => $found){
		if (contain($key, "count")) continue;
			if (!empty($found['employeeid'][0])) {
				//$checkid[$found['employeeid']] = array('employeeid' => $found['employeeid'], 'samaccountname' => $found['samaccountname'], 'mail' => $found['mail'],'dn' => $found['dn']);
				$checkid[] = $found['employeeid'];
			}
		}
			
$check = json_encode($checkid);
$infoarray = array();

$response .= "
<table id='fresh-table' class='table'>
   <thead>
  <tr>
    <th data-field='id'>#</th>
    <th data-field='fullname'>Fullname</th>
    <th data-field='email'>Email</th>
    <th data-field='studentid'>StudentID</th>
    <th data-field='password'>Password</th>
    <th data-field='location'>Location</th>
	<th data-field='status'>Status</th>
  </tr>
     </thead>";	
$ou = array();
$combinedarray = array();

//Find all Folders for Location information.
$folders = _getfolder($importldap->folder()->listing(NULL, adLDAP::ADLDAP_FOLDER, true, 'folder'));
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
foreach($csv as $ckey => $items) {
	$userarray = array();
	$kid = array_values(preg_grep_keys ('/^(LOCAL_STUDENT_ID)$|^(STUDENT_ID)$/i', (array) $items))[0];

	if (!empty($checkid[$kid])){
		unset($csv[$ckey]);
		continue;
	}	
	foreach($items as $key => $value){
		if ($value == null)
			continue;
		if (contains($key, "School_Name") || $key == "NAME") {
            $userarray['office'] = $value;
        }
        if (contains($key, "Last_Name")) {
            $userarray['sn'] = ($value);
        }
        elseif (contains($key, "First_Name")) {
            $userarray['givenname'] = ($value);
        }      
		elseif (contains($key, "Middle_Name")) {
			if (!empty($value)) {
            $userarray['middlename'] = ($value);
			$userarray['initials'] = strtoupper($value[0]);
			}
		}
        elseif (contains($key, "SchoolID") || contains($key, "SCHOOL_ID")) {
            $userarray['localeid'] = $value;
			if (empty($userarray['office']))
					$userarray['office'] = $localeid[$value];
							
			if (($value >= 6000) && ($value <= 6050)  ){
				$userarray['private'] = true;
			}
        }
		elseif (contains($key, "Student_Number") || contains($key, "Student#") || contains($key, "Local_Student_Id")) {
			$userarray['employeeid'] = ($userarray['private'] && !empty($value)) ? strtolower($ou[$userarray['localeid']]['name']).$value : $value;
        }
        elseif (contains($key, "Grade_Level")) {
			$userarray['gidnumber'] = ($value);
        }		
        elseif (contains($key, "DOB")) {
			date_default_timezone_set('America/St_Thomas');
			$userarray['password'] = date('mdY', strtotime($value));			
        }
        else {
			//Do not care about other values
            //$userarray[$key] = $value;
        }
	}
	
$parser = new nameParser();
	if (!empty($userarray['middlename'])){
			$parser->setFullName($userarray['sn'].", ".$userarray['givenname']);
		} else {
			$parser->setFullName($userarray['sn'].", ". $userarray['middlename'] ." ".$userarray['givenname']);
		}
		 $parser->parse();

	if (empty($parser->getMiddleName())){
		$fname = array($parser->getFirstName(),$parser->getLastName());
		$fname[] = substr($userarray["employeeid"], -3);
		} else {
			$userarray['initials'] = strtoupper($parser->getMiddleName()[0]);
			if ($parser->getMiddleName() != $user['middlename']){
				$fname = array($parser->getFirstName(), $parser->getLastName());
			} else {
				$fname = array($parser->getFirstName(), $parser->getMiddleName(), $parser->getLastName());
			}
			$fname[] = substr($userarray["employeeid"], -3);
		}

		$uid = username(createuser($fname), $userarray['employeeid']);
		$lid = $userarray['localeid'];

		$createarray['username'] = $uid;
		$createarray['logon_name'] = $uid.'@stu.vide.vi';
		$createarray['firstname'] = $userarray['givenname'];
		$createarray['surname'] = $userarray['sn'];
		$createarray['department'] = $userarray['office'];
		$createarray['company'] = $userarray['office'];
		$createarray['email'] = $uid.'@stu.vide.vi';	
		
		if (!empty($userarray['middlename'])){
			$createarray['displayname'] = $createarray['firstname'] . " " . $userarray['middlename'] . " " .$createarray['surname'];
		} else {
			$createarray['displayname'] = $createarray['firstname'] . " " .$createarray['surname'];
		}

		if (count(parseLdapDn($ou[$lid]['dn'])) >= 1) {
			$ddn = array_reverse(parseLdapDn($ou[$lid]['dn'],false)['ou']);
			$createarray['container'] = $ddn;
			
				if ($userarray['private']) {
					if (empty($userarray['password']))
						array_push($createarray['container'],"Teachers and Staff");
				
					array_push($createarray['container'],"Students");
				}
		} else {
			$createarray['container'] = array('New Users');
			$userarray['ddn'] = 'New Users';
		}
		
		$createarray['enabled'] = 1;
		$createarray['password'] = strtoupper($parser->getFirstName()[0]).strtolower($parser->getLastName()[0]).$userarray['password'] . "!";
		
		$userarray['suid'] = strtoupper($uid[0]).strtolower(explode('.', $uid)[1][0]).$userarray['employeeid'];
		$userarray['password'] = strtoupper($uid[0]).strtolower(explode('.', $uid)[1][0]).$userarray['password'];

		//if (strlen($userarray['password']) <= 3) {
			$userarray['password'] = $g->generate();
			$createarray['password'] = $g->generate();
		//}
		
		$combinedarray[] = array("c" => array($createarray), "u" => array($userarray));
	}
	
	$i = 0;
	foreach($combinedarray as $infoarr) {
	$userarr = $infoarr["u"][0];
	$createarr = $infoarr["c"][0];

		$createuser = array(
			'employeetype' => 'student',
			'middlename' => $userarr['middlename'],
			'initials' => $userarr['initials'],
			'employeeid' => $userarr['employeeid'],
			'employeenumber' => $userarr['password'],
			'department' => $userarr['office'],
			'company' => $userarr['office'],
			'physicalDeliveryOfficeName' => $userarr['office'],	
			'displayname' => $createarr['displayname'],
			'description' => $userarr['office']
			);
			
			// Clean up empty values
			foreach($createuser as $key=>$value){
				if(is_null($value) || $value == '' || empty($value)) unset($createuser[$key]);
			}

		foreach ($checkid as $dup) {
		 if (($userarr['employeeid']) == $dup){
			$duplicate = true;
			$modarr = array(
			'userprincipalname' => $createarr['email'],
			'samaccountname' => $createarr['username'],
			'mail' => $createarr['email'],
			'givenname' => $userarr['givenname'],
			'sn' => $userarr['sn'],
			'description' => $userarr['office']
			);
			break;
		 }
		 $duplicate = false;
		}
		
		try {
			
			//check POST variable
			if ($data["importcreate"] == "1" && $duplicate == false) {
				$create = $importldap->user()->create($createarr);
			}
			
		if ($duplicate){
		//Get user old userid
		$fdata = _searchldapcache($userarr['employeeid'][0]);
		 if (!isEmpty($fdata))
			$founduser = _searchusercache($userarr['employeeid'], $fdata);

			$olduser = $importldap->user()->info($founduser[0]['samaccountname'][0], array('targetaddress', 'extensionattribute15'));
			$newtarget = 'SMTP:' . $createarr['username']. '@' . explode('@', $olduser[0]['targetaddress'][0])[1];
		}
		
		$emailprefix = explode('@', $createarr['email'])[1];		
	if ($create){
		$importldap->user()->modify($createarr['username'], $createuser);		
		//Update other values after creation
		$importldap->user()->password($createarr['username'], $userarr['password']);
		$importldap->user()->modify($createarr['username'], array('pwdlastset' => '000'));
		$importldap->user()->modify($createarr['username'], array('gidnumber' => $createarr['gidnumber']));
		$importldap->user()->modify($createarr['username'],array('localeid' => $userarr['localeid'])); // LocaleID
				
		$importldap->group()->addUser('Students', $createarr['username']);
		$importldap->group()->addUser($ou[$lid]['name'].'StuWAP', $createarr['username']);
				
		$importldap->exchange()->addAddress($createarr['username'], $userarr['employeeid'].'@'.$emailprefix);
		$importldap->exchange()->addAddress($createarr['username'], $createarr['email']);
		$importldap->exchange()->primaryAddress($createarr['username'], $createarr['email']);
		
		//$newtarget = 'SMTP:' . $createarr['username']. '@' . explode('@', $createarr['email'])[1];
		//$importldap->user()->modify($createarr['username'], array('targetaddress' => $newtarget));
		
		$result = "Created:".json_encode($importldap->getLastError());
		 } else if ($data["importcreate"] == "1" && $duplicate){
			
			$modify = $importldap->user()->modify($founduser[0]['samaccountname'][0], $modarr);
				$importldap->user()->modify($createarr['username'], $createuser);
			
			$groups = $importldap->user()->groups($createarr['username']);
			
		if ($modify){
			#Move to new location.	
			$container = array_reverse(parseLdapDn($ou[$userarr['localeid']]['dn'], false)['ou']);
			$mvresult = $importldap->user()->move($createarr['username'], $container,  $createarr['displayname']); 
			$newtarget = 'SMTP:' . $createarr['username']. '@' .$emailprefix;
						
			//Update group assignment
			if ($createarr['localeid'] != $userarr['localeid']){
			$importldap->user()->modify($createarr['username'],array('localeid' => $userarr['localeid'])); // LocaleID
			
				foreach ($groups as $group){ //remove OLD WAP
					if (contains($group, "stuwap"))
						$importldap->group()->removeUser($group, $createarr['username']);
				}
			$importldap->group()->addUser($ou[$userarr['localeid']]['name'].'StuWAP', $createarr['username']);
			}
			//Update other values for modification if username has changed
		//if ($founduser[0]['samaccountname'][0] !== $createarr['username']){
			#Update the password established in employeenumber
			
			#Update Password
			//$importldap->user()->password($createarr['username'], $userarr['password']);
			//$importldap->user()->modify($createarr['username'], array('pwdlastset' => '000'));
			
			//Update Username if its changed.
			//$importldap->user()->modify($founduser[0]['samaccountname'][0] , array('samaccountname' => $createarr['username']));
			
			$importldap->user()->modify($createarr['username'], array('extensionattribute15' => array()));
			$importldap->user()->modify($createarr['username'], array('gidnumber' => $createarr['gidnumber']));
			#Email Attributes
			$importldap->exchange()->deleteAddress($createarr['username'], $founduser[0]['samaccountname'][0].$emailprefix); //remove old email
			$importldap->exchange()->addAddress($createarr['username'], $createarr['email']);
			$importldap->exchange()->primaryAddress($createarr['username'], $createarr['email']);
			$importldap->user()->modify($createarr['username'], array('targetaddress' => $newtarget));
		//}
				
	}
		$result = 'Modify: ' . json_encode($importldap->getLastError());
		} else {
			$result = json_encode($importldap->getLastError());
		 }	
		}
		catch (adLDAPException $e) {
		$result = "ERROR: ".$e;
		error_log($result . ":" . json_encode($createarr));
		}
//if ($data["importcreate"] !== "1")	
	//$getuser = $importldap->user()->find(true, 'samaccountname', $createarr['username']);

  $response.= "<tr>
    <td>". $i."</td>
    <td" . ($duplicate ? " style='color:#FF0000;' data-container='body' data-toggle='popover' data-placement='top' data-content='".(($founduser[0]['samaccountname'][0]))."'>" : '>') . ($duplicate ? "<strong>" .$createarr['displayname']. "</strong>" :  $createarr['displayname'] )."</td>
    <td>". $createarr['email']."</td>
    <td>". $userarr['employeeid']."</td>
    <td>". $userarr['password']."</td>
    <td>". $userarr['localeid']."</td>";
	if (contains($result, "ERROR") || contains($result, "Already")){
		$response .= "<td style='color:#FF0000;'>".$result."</td></tr>" ;
	} else if ($data["importcreate"] !== "1" && !$duplicate){
		$response .= "<td><i class='fa fa-check fa-lg'></i></td></tr>";
		} else if ($data["importcreate"] == "0" && !$duplicate){
		$response .= "<td>".$result."</td></tr>";	
		} else {
		$response .= "<td" .($duplicate ? ' style="color:#FF0000;"' : '') . " class='success'>". ($duplicate && $data["importcreate"] == "0"? ' Duplicate Entry' : $result)."</td></tr>";
	}	
	
	$i++;
}
 $response .= "</tbody>
  </table>";
  
	echo json_encode(array(
        "info" => ($response),	
        "result" => 0
		));
		if ($data["importcreate"] == "1"){
	$import_mail = new pssm_Mail();
	$import_mail->setTo('all_techs@vide.vi', 'All Techs')
		->addMailHeader('Cc:VIDE Managers <vide_user_managers@vide.vi>')
		->setSubject('Student IMPORT Report')
		->setParameters('-f admin')
		->setFrom('no.notify@vide.vi', 'No Reply')
		 ->addMailHeader('Reply-To', _get_setting('system_email'), trim(_get_setting('system_name')))
		->addGenericHeader('X-Priority', '1 (Highest)')
		->addGenericHeader('Importance', 'High')
		->addGenericHeader('X-MS-Exchange-Organization-BypassClutter', 'true')	
		->addGenericHeader('X-Mailer', $_pssm_name . ' ' . $_pssm_version)
		->setMessage($respond);
		$admin_mail->send();
		$m->info(
			'import',
			$username . " imported file ".$_FILES["file"]["name"]." successfully"
		);
		}
        break;

    //******************************************DOP*******************************
    case "masterlist":
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
        break;
    default:
}
?>