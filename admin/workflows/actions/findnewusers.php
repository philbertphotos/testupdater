<?php
/*************************************************************************
 * Action_FindNewUsers
 *************************************************************************
 * Workflow action key:
 * users.find.new
 *
 * Purpose:
 * Find newly-created Active Directory users and place them into the workflow
 * context so the next workflow action can audit them against UM standards.
 *
 * Expected location:
 * /admin/workflows/actions/findnewusers.php
 *
 * Expected PHP class:
 * Action_FindNewUsers
 *
 * Expected registry entry:
 * 'users.find.new' => 'Action_FindNewUsers'
 *
 * Expected workflow URL test:
 * /admin/workflows/api.php?key=users.find.new
 *
 * Expected step parameters example:
 *
 * {
 *     "days":7,
 *     "domain":"vi.gov",
 *     "include_disabled":true
 * }
 *
 * Multi-domain example:
 *
 * {
 *     "days":7,
 *     "domains":["vi.gov","stu.k12.vi"],
 *     "include_disabled":true
 * }
 *
 * Custom LDAP filter example:
 *
 * {
 *     "domain":"vi.gov",
 *     "filter":"(&(objectCategory=person)(objectClass=user)(whenCreated>=20260801000000.0Z))"
 * }
 *************************************************************************/
class Action_FindNewUsers implements WorkflowActionInterface
{
	/**
	 * Run action.
	 *
	 * @param array  $context
	 * @param array  $params
	 * @param object $engine
	 *
	 * @return array
	 */
	public function run($context, $params, $engine)
	{
		$days = isset($params['days']) ? (int)$params['days'] : 7;

		if ($days <= 0) {
			$days = 7;
		}

		$includeDisabled = isset($params['include_disabled']) ? filter_var($params['include_disabled'], FILTER_VALIDATE_BOOLEAN) : true;
		$domains = $this->getDomains($params);

		if (count($domains) == 0) {
			throw new Exception('Action_FindNewUsers requires a domain or domains array.');
		}

		$users = array();
		$fields = $this->getFields($params);
		$filter = $this->buildFilter($days, $params, $includeDisabled);

		foreach ($domains as $domain) {
			$ad = $this->getAdConnection($domain);

			if (!$ad) {
				continue;
			}

			try {
				/*************************************************************************
				 * Existing UM LDAP style:
				 * $users = $m->getAD('vi.gov');
				 * $users->user()->all($filter, $fields, '', true, true);
				 *************************************************************************/
				$results = $ad->user()->all($filter, $fields, '', true, true);

				if (!is_array($results)) {
					continue;
				}

				foreach ($results as $key => $record) {
					if ($key === 'count' || !is_array($record)) {
						continue;
					}

					$users[] = $this->normaliseUser($record, $domain);
				}
			} catch (Exception $e) {
				continue;
			}
		}

		$context['new_users'] = array(
			'source' => 'ldap',
			'days' => $days,
			'domains' => $domains,
			'filter' => $filter,
			'count' => count($users),
			'users' => $users
		);

		/**
		 * Short alias for follow-up workflow actions.
		 * The detailed payload remains available in new_users.
		 */
		$context['users'] = $users;

		if (!isset($context['stats']) || !is_array($context['stats'])) {
			$context['stats'] = array();
		}

		$context['stats']['new_users'] = count($users);

		return $context;
	}

	/*************************************************************************
	 * LDAP Connection Helpers
	 *************************************************************************/

	/**
	 * Return AD connection using the current UM style.
	 *
	 * Preferred:
	 * $m->getAD($domain)
	 *
	 * Fallback:
	 * _getad($domain)
	 *
	 * @param string $domain
	 *
	 * @return mixed
	 */
	protected function getAdConnection($domain)
	{
		global $m;

		if (is_object($m) && method_exists($m, 'getAD')) {
			return $m->getAD($domain);
		}

		if (function_exists('_getad')) {
			return _getad($domain);
		}

		throw new Exception('No LDAP connection helper found. Expected $m->getAD() or _getad().');
	}

	/**
	 * Get domains from workflow parameters.
	 *
	 * @param array $params
	 *
	 * @return array
	 */
	protected function getDomains($params)
	{
		$domains = array();

		if (isset($params['domains']) && is_array($params['domains'])) {
			foreach ($params['domains'] as $domain) {
				$domain = trim((string)$domain);

				if ($domain !== '') {
					$domains[] = $domain;
				}
			}
		}

		if (isset($params['domain'])) {
			$domain = trim((string)$params['domain']);

			if ($domain !== '') {
				$domains[] = $domain;
			}
		}

		return array_values(array_unique($domains));
	}

	/*************************************************************************
	 * LDAP Query Helpers
	 *************************************************************************/

	/**
	 * Fields needed by standards audit action.
	 *
	 * @param array $params
	 *
	 * @return array
	 */
	protected function getFields($params)
	{
		if (isset($params['fields']) && is_array($params['fields']) && count($params['fields']) > 0) {
			return $params['fields'];
		}

		return array(
			'samaccountname',
			'userprincipalname',
			'mail',
			'givenname',
			'sn',
			'displayname',
			'employeeid',
			'department',
			'title',
			'manager',
			'whencreated',
			'useraccountcontrol',
			'distinguishedname',
			'dn'
		);
	}

	/**
	 * Build LDAP filter for newly-created users.
	 *
	 * If a custom filter is supplied in workflow parameters, use it as-is.
	 * This allows you to tune the action without changing PHP later.
	 *
	 * @param int   $days
	 * @param array $params
	 * @param bool  $includeDisabled
	 *
	 * @return string
	 */
	protected function buildFilter($days, $params, $includeDisabled)
	{
		if (isset($params['filter']) && trim((string)$params['filter']) !== '') {
			return trim((string)$params['filter']);
		}

		$cutoff = gmdate('YmdHis.0\Z', time() - ($days * 86400));

		if ($includeDisabled) {
			return '(&(objectCategory=person)(objectClass=user)(whenCreated>='.$cutoff.'))';
		}

		return '(&(objectCategory=person)(objectClass=user)(whenCreated>='.$cutoff.')(!(userAccountControl:1.2.840.113556.1.4.803:=2)))';
	}

	/*************************************************************************
	 * User Normalisation Helpers
	 *************************************************************************/

	/**
	 * Convert LDAP record into a consistent structure.
	 *
	 * @param array  $record
	 * @param string $source
	 *
	 * @return array
	 */
	protected function normaliseUser($record, $source)
	{
		return array(
			'source' => $source,
			'samaccountname' => $this->value($record, 'samaccountname'),
			'userprincipalname' => $this->value($record, 'userprincipalname'),
			'mail' => $this->value($record, 'mail'),
			'givenname' => $this->value($record, 'givenname'),
			'sn' => $this->value($record, 'sn'),
			'displayname' => $this->value($record, 'displayname'),
			'employeeid' => $this->value($record, 'employeeid'),
			'department' => $this->value($record, 'department'),
			'title' => $this->value($record, 'title'),
			'manager' => $this->value($record, 'manager'),
			'whencreated' => $this->value($record, 'whencreated'),
			'useraccountcontrol' => $this->value($record, 'useraccountcontrol'),
			'dn' => ($this->value($record, 'dn') !== '' ? $this->value($record, 'dn') : $this->value($record, 'distinguishedname'))
		);
	}

	/**
	 * Read one value from LDAP-style arrays or normal arrays.
	 *
	 * @param array  $record
	 * @param string $key
	 *
	 * @return string
	 */
	protected function value($record, $key)
	{
		if (!isset($record[$key])) {
			return '';
		}

		$value = $record[$key];

		if (is_array($value)) {
			if (isset($value[0])) {
				return trim((string)$value[0]);
			}

			return '';
		}

		return trim((string)$value);
	}
}
?>