<?php
/*
 * Custom Action: createuser
 * ------------------------------------------------------------
 * This file replaces case 'createuser' from the old custom.php.
 */
   require_once (DOCROOT.'/thirdparty/PWGen.php');

    $pwgen = new PWGen();
    $pwgen->setLength(8);
	$pwgen->setSecure(false);
	$pwgen->setNumerals(true);
    $pwgen->setNoVovels(false);
    $pwgen->setSymbols(false);
	$csvarray->password = $g->generate();

foreach($_POST as $key => $value)
    $csvarray->$key = $value;
		$container = array_reverse(parseLdapDn($csvarray->container, false)['ou']);
		
		$createarray['username'] = $csvarray->samaccountname;
		$createarray['logon_name'] = $csvarray->samaccountname.'@'.$csvarray->suffix;
		$createarray['firstname'] = $csvarray->givenname;
		$createarray['surname'] = $csvarray->sn;
		$createarray['department'] = trim($csvarray->department);
		$createarray['company'] = trim($csvarray->company);
		$createarray['email'] = $createarray['logon_name'];		
		$createarray['container'] = $container;
		$createarray['enabled'] = 1;
		$createarray['password'] = $csvarray->password;

		//Only create users that do not currently exist.
		if (!empty($checkid[$csvarray->employeeid])) continue;
		try {
			if (empty($csvarray->container)){
				echo json_encode(array(
				"info" => "Organizational Unit is empty",	
				"post" => $_POST,	
				"result" => 1
				));
				return;
			}
		$createldap = _getad($csvarray->container);		
		$create = $createldap->user()->create($createarray);		

		if ($create){
			_save_log('createuser', 'info', $username . " created '".$csvarray->samaccountname);
				//Update user info after creation
			$update = $createldap->user()->modify($createarray['username'], array(
				'employeeType' => 'staff',
				'middlename' => $csvarray->middlename,
				'initials' => $csvarray->initials,
				'employeeid' => $csvarray->employeeid,
				'postalcode' => $csvarray->postalcode,
				'description' => trim($csvarray->description),
				'physicaldeliveryofficename' => trim($csvarray->physicaldeliveryofficename),
				'title' => $csvarray->title,
				'pwdLastSet' => '000',
				'lockoutTime' => 0,
				));
				
			foreach($csvarray->groups as $group) 
				$createldap->group()->addUser($group, $createarray['username']);
				
				//$ pwsh -c 'if(-not (Get-Module -ListAvailable -Name VMware.PowerCLI)){ exit 1 }' && echo yes || echo no yes
				//$ pwsh -c 'if(-not (Get-Module -ListAvailable -Name BadModuleName)){ exit 1 }' && echo yes || echo no no
			
		} else {
		_save_log('createuser', 'error', $username . " failed (". $csvarray->samaccountname .") reason: ". $createldap->getLastError());
		//_save_log('createuser', 'debug', $username . " failed (". json_encode($createarray .") ");
		
		echo json_encode(array(
        "info" => json_encode("Failed to create (" . $csvarray->samaccountname . ") error: " . ($createldap->getLastError())),	
        //"info" => json_encode("Failed to create (" . $csvarray->samaccountname . ") error: " . ($createldap->getLastError()) . "<br>debug: " . json_encode($createarray)),	
        "result" => 1
		));
		return;
		}
	} catch (adLDAPException $e) {
		echo json_encode(array(
        "info" => json_encode($e),	
        "result" => 1
		));
		_save_log('createuser', 'error', $username . " failed '". $csvarray->samaccountname ."' reason: ". $e->getMessage());
		}
	echo json_encode(array(
        "create" => $createarray['username'],	
        "update" => json_encode($update),
        "password" => $csvarray->password,
        "result" => 0
		));