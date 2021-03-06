<?php
//var_dump($_SERVER['REQUEST_METHOD'],$_SERVER['PATH_INFO']); die();

interface DatabaseInterface {
	public function getSql($name);
	public function connect($hostname,$username,$password,$database,$port,$socket,$charset);
	public function query($sql,$params);
	public function fetchAssoc($result);
	public function fetchRow($result);
	public function insertId($result);
	public function affectedRows($result);
	public function close($result);
	public function fetchFields($table);
	public function addLimitToSql($sql,$limit,$offset);
	public function likeEscape($string);
	public function isBinaryType($field);
	public function base64Encode($string);
	public function getDefaultCharset();
}

class MySQL implements DatabaseInterface {

	protected $db;
	protected $queries;

	public function __construct() {
		$this->queries = array(
			'list_tables'=>'SELECT
					"TABLE_NAME","TABLE_COMMENT"
				FROM
					"INFORMATION_SCHEMA"."TABLES"
				WHERE
					"TABLE_SCHEMA" = ?',
			'reflect_table'=>'SELECT
					"TABLE_NAME"
				FROM
					"INFORMATION_SCHEMA"."TABLES"
				WHERE
					"TABLE_NAME" COLLATE \'utf8_bin\' = ? AND
					"TABLE_SCHEMA" = ?',
			'reflect_pk'=>'SELECT
					"COLUMN_NAME"
				FROM
					"INFORMATION_SCHEMA"."COLUMNS"
				WHERE
					"COLUMN_KEY" = \'PRI\' AND
					"TABLE_NAME" = ? AND
					"TABLE_SCHEMA" = ?',
			'reflect_belongs_to'=>'SELECT
					"TABLE_NAME","COLUMN_NAME",
					"REFERENCED_TABLE_NAME","REFERENCED_COLUMN_NAME"
				FROM
					"INFORMATION_SCHEMA"."KEY_COLUMN_USAGE"
				WHERE
					"TABLE_NAME" COLLATE \'utf8_bin\' = ? AND
					"REFERENCED_TABLE_NAME" COLLATE \'utf8_bin\' IN ? AND
					"TABLE_SCHEMA" = ? AND
					"REFERENCED_TABLE_SCHEMA" = ?',
			'reflect_has_many'=>'SELECT
					"TABLE_NAME","COLUMN_NAME",
					"REFERENCED_TABLE_NAME","REFERENCED_COLUMN_NAME"
				FROM
					"INFORMATION_SCHEMA"."KEY_COLUMN_USAGE"
				WHERE
					"TABLE_NAME" COLLATE \'utf8_bin\' IN ? AND
					"REFERENCED_TABLE_NAME" COLLATE \'utf8_bin\' = ? AND
					"TABLE_SCHEMA" = ? AND
					"REFERENCED_TABLE_SCHEMA" = ?',
			'reflect_habtm'=>'SELECT
					k1."TABLE_NAME", k1."COLUMN_NAME",
					k1."REFERENCED_TABLE_NAME", k1."REFERENCED_COLUMN_NAME",
					k2."TABLE_NAME", k2."COLUMN_NAME",
					k2."REFERENCED_TABLE_NAME", k2."REFERENCED_COLUMN_NAME"
				FROM
					"INFORMATION_SCHEMA"."KEY_COLUMN_USAGE" k1,
					"INFORMATION_SCHEMA"."KEY_COLUMN_USAGE" k2
				WHERE
					k1."TABLE_SCHEMA" = ? AND
					k2."TABLE_SCHEMA" = ? AND
					k1."REFERENCED_TABLE_SCHEMA" = ? AND
					k2."REFERENCED_TABLE_SCHEMA" = ? AND
					k1."TABLE_NAME" COLLATE \'utf8_bin\' = k2."TABLE_NAME" COLLATE \'utf8_bin\' AND
					k1."REFERENCED_TABLE_NAME" COLLATE \'utf8_bin\' = ? AND
					k2."REFERENCED_TABLE_NAME" COLLATE \'utf8_bin\' IN ?'
		);
	}

	public function getSql($name) {
		return isset($this->queries[$name])?$this->queries[$name]:false;
	}

	public function connect($hostname,$username,$password,$database,$port,$socket,$charset) {
		$db = mysqli_connect($hostname,$username,$password,$database,$port,$socket);
		if (mysqli_connect_errno()) {
			throw new \Exception('Connect failed. '.mysqli_connect_error());
		}
		if (!mysqli_set_charset($db,$charset)) {
			throw new \Exception('Error setting charset. '.mysqli_error($db));
		}
		if (!mysqli_query($db,'SET SESSION sql_mode = \'ANSI_QUOTES\';')) {
			throw new \Exception('Error setting ANSI quotes. '.mysqli_error($db));
		}
		$this->db = $db;
	}

	public function query($sql,$params) {
		$db = $this->db;
		$sql = preg_replace_callback('/\!|\?/', function ($matches) use (&$db,&$params) {
			$param = array_shift($params);
			if ($matches[0]=='!') return preg_replace('/[^a-zA-Z0-9\-_=<> ]/','',$param);
			if (is_array($param)) return '('.implode(',',array_map(function($v) use (&$db) {
				return "'".mysqli_real_escape_string($db,$v)."'";
			},$param)).')';
			if (is_object($param) && $param->type=='base64') {
				return "x'".bin2hex(base64_decode($param->data))."'";
			}
			if ($param===null) return 'NULL';
			return "'".mysqli_real_escape_string($db,$param)."'";
		}, $sql);
		//if (!strpos($sql,'INFORMATION_SCHEMA'))	echo "\n$sql\n";
		return mysqli_query($db,$sql);
	}

	public function fetchAssoc($result) {
		return mysqli_fetch_assoc($result);
	}

	public function fetchRow($result) {
		return mysqli_fetch_row($result);
	}

	public function insertId($result) {
		return mysqli_insert_id($this->db);
	}

	public function affectedRows($result) {
		return mysqli_affected_rows($this->db);
	}

	public function close($result) {
		return mysqli_free_result($result);
	}

	public function fetchFields($table) {
		$result = $this->query('SELECT * FROM "!" WHERE 1=2;',array($table));
		return mysqli_fetch_fields($result);
	}

	public function addLimitToSql($sql,$limit,$offset) {
		return "$sql LIMIT $limit OFFSET $offset";
	}

	public function likeEscape($string) {
		return addcslashes($string,'%_');
	}

	public function isBinaryType($field) {
		//echo "$field->name: $field->type ($field->flags)\n";
		return (($field->flags & 128) && ($field->type>=249) && ($field->type<=252));
	}

	public function base64Encode($string) {
		return base64_encode($string);
	}

	public function getDefaultCharset() {
		return 'utf8';
	}

}

 

class PHP_CRUD_API {

	protected $db;
	protected $settings;

	protected function mapMethodToAction($method,$key) {
		switch ($method) {
			case 'OPTIONS': return 'headers';
			case 'GET': return $key?'read':'list';
			case 'PUT': return 'update';
			case 'POST': return 'create';
			case 'DELETE': return 'delete';
			default: $this->exitWith404('method');
		}
		return false;
	}

	protected function parseRequestParameter(&$request,$characters) {
		if (!$request) return false;
		$pos = strpos($request,'/');
		$value = $pos?substr($request,0,$pos):$request;
		$request = $pos?substr($request,$pos+1):'';
		if (!$characters) return $value;
		return preg_replace("/[^$characters]/",'',$value);
	}

	protected function parseGetParameter($get,$name,$characters) {
		$value = isset($get[$name])?$get[$name]:false;
		return $characters?preg_replace("/[^$characters]/",'',$value):$value;
	}

	protected function parseGetParameterArray($get,$name,$characters) {
		$values = isset($get[$name])?$get[$name]:false;
		if (!is_array($values)) $values = array($values);
		if ($characters) {
			foreach ($values as &$value) {
				$value = preg_replace("/[^$characters]/",'',$value);
			}
		}
		return $values;
	}

	protected function applyTableAuthorizer($callback,$action,$database,&$tables) {
		if (is_callable($callback,true)) foreach ($tables as $i=>$table) {
			if (!$callback($action,$database,$table)) {
				unset($tables[$i]);
			}
		}
	}

	protected function applyRecordFilter($callback,$action,$database,$tables,&$filters) {
		if (is_callable($callback,true)) foreach ($tables as $i=>$table) {
			$f = $this->convertFilters($callback($action,$database,$table));
			if ($f) {
				if (!isset($filters[$table])) $filters[$table] = array();
				if (!isset($filters[$table]['and'])) $filters[$table]['and'] = array();
				$filters[$table]['and'] = array_merge($filters[$table]['and'],$f);
			}
		}
	}

	protected function applyTenancyFunction($callback,$action,$database,$fields,&$filters) {
		if (is_callable($callback,true)) foreach ($fields as $table=>$keys) {
			foreach ($keys as $field) {
				$v = $callback($action,$database,$table,$field->name);
				if ($v!==null) {
					if (!isset($filters[$table])) $filters[$table] = array();
					if (!isset($filters[$table]['and'])) $filters[$table]['and'] = array();
					$filters[$table]['and'][] = array($field->name,is_array($v)?'IN':'=',$v);
				}
			}
		}
	}

	protected function applyColumnAuthorizer($callback,$action,$database,&$fields) {
		if (is_callable($callback,true)) foreach ($fields as $table=>$keys) {
			foreach ($keys as $field) {
				if (!$callback($action,$database,$table,$field->name)) {
					unset($fields[$table][$field->name]);
				}
			}
		}
	}

	protected function applyInputTenancy($callback,$action,$database,$table,&$input,$keys) {
		if (is_callable($callback,true)) foreach ($keys as $key=>$field) {
			$v = $callback($action,$database,$table,$key);
			if ($v!==null) {
				if (is_array($v)) {
					if (!count($v)) {
						$input->$key = null;
					} elseif (!isset($input->$key)) {
						$input->$key = $v[0];
					} elseif (!in_array($input->$key,$v)) {
						$input->$key = null;
					}
				} else {
					$input->$key = $v;
				}
			}
		}
	}

	protected function applyInputSanitizer($callback,$action,$database,$table,&$input,$keys) {
		if (is_callable($callback,true)) foreach ((array)$input as $key=>$value) {
			if (isset($keys[$key])) {
				$input->$key = $callback($action,$database,$table,$key,$keys[$key]->type,$value);
			}
		}
	}

	protected function applyInputValidator($callback,$action,$database,$table,$input,$keys,$context) {
		$errors = array();
		if (is_callable($callback,true)) foreach ((array)$input as $key=>$value) {
			if (isset($keys[$key])) {
				$error = $callback($action,$database,$table,$key,$keys[$key]->type,$value,$context);
				if ($error!==true && $error!==null) $errors[$key] = $error;
			}
		}
		if (!empty($errors)) $this->exitWith422($errors);
	}

	protected function processTableAndIncludeParameters($database,$table,$include,$action) {
		$blacklist = array('information_schema','mysql','sys','pg_catalog');
		if (in_array(strtolower($database), $blacklist)) return array();
		$table_list = array();
		if ($result = $this->db->query($this->db->getSql('reflect_table'),array($table,$database))) {
			while ($row = $this->db->fetchRow($result)) $table_list[] = $row[0];
			$this->db->close($result);
		}
		if (empty($table_list)) $this->exitWith404('entity');
		if ($action=='list') {
			foreach (explode(',',$include) as $table) {
				if ($result = $this->db->query($this->db->getSql('reflect_table'),array($table,$database))) {
					while ($row = $this->db->fetchRow($result)) $table_list[] = $row[0];
					$this->db->close($result);
				}
			}
		}
		return $table_list;
	}

	protected function exitWith404($type) {
		if (isset($_SERVER['REQUEST_METHOD'])) {
			header('Content-Type:',true,404);
			die("Not found ($type)");
		} else {
			throw new \Exception("Not found ($type)");
		}
	}

	protected function exitWith422($object) {
		if (isset($_SERVER['REQUEST_METHOD'])) {
			header('Content-Type:',true,422);
			die(json_encode($object));
		} else {
			throw new \Exception(json_encode($object));
		}
	}

	protected function headersCommand($parameters) {
		$headers = array();
		$headers[]='Access-Control-Allow-Headers: Content-Type';
		$headers[]='Access-Control-Allow-Methods: OPTIONS, GET, PUT, POST, DELETE';
		$headers[]='Access-Control-Allow-Credentials: true';
		$headers[]='Access-Control-Max-Age: 1728000';
		if (isset($_SERVER['REQUEST_METHOD'])) {
			foreach ($headers as $header) header($header);
		} else {
			echo json_encode($headers);
		}
	}

	protected function startOutput($callback) {
		if ($callback) {
			if (isset($_SERVER['REQUEST_METHOD'])) {
				header('Content-Type: application/javascript; charset=utf-8');
			}
			echo $callback.'(';
		} else {
			if (isset($_SERVER['REQUEST_METHOD'])) {
				header('Content-Type: application/json; charset=utf-8');
			}
		}
	}

	protected function endOutput($callback) {
		if ($callback) {
			echo ');';
		}
	}

	protected function processKeyParameter($key,$tables,$database) {
		if (!$key) return false;
		$count = 0;
		$field = false;
		if ($result = $this->db->query($this->db->getSql('reflect_pk'),array($tables[0],$database))) {
			while ($row = $this->db->fetchRow($result)) {
				$count++;
				$field = $row[0];
			}
			$this->db->close($result);
		}
		if ($count!=1 || $field==false) $this->exitWith404('1pk');
		return array($key,$field);
	}

	protected function processOrderParameter($order) {
		if (!$order) return false;
		$order = explode(',',$order,2);
		if (count($order)<2) $order[1]='ASC';
		if (!strlen($order[0])) return false;
		$order[1] = strtoupper($order[1])=='DESC'?'DESC':'ASC';
		return $order;
	}

	protected function convertFilter($field, $comparator, $value) {
		switch (strtolower($comparator)) {
			case 'cs': $comparator = 'LIKE'; $value = '%'.$this->db->likeEscape($value).'%'; break;
			case 'sw': $comparator = 'LIKE'; $value = $this->db->likeEscape($value).'%'; break;
			case 'ew': $comparator = 'LIKE'; $value = '%'.$this->db->likeEscape($value); break;
			case 'eq': $comparator = '='; break;
			case 'ne': $comparator = '<>'; break;
			case 'lt': $comparator = '<'; break;
			case 'le': $comparator = '<='; break;
			case 'ge': $comparator = '>='; break;
			case 'gt': $comparator = '>'; break;
			case 'in': $comparator = 'IN'; $value = explode(',',$value); break;
			case 'ni': $comparator = 'NOT IN'; $value = explode(',',$value); break;
			case 'is': $comparator = 'IS'; $value = null; break;
			case 'no': $comparator = 'IS NOT'; $value = null; break;
		}
		return array($field, $comparator, $value);
	}

	protected function convertFilters($filters) {
		$result = array();
		if ($filters) {
			for ($i=0;$i<count($filters);$i++) {
				$filter = explode(',',$filters[$i],3);
				if (count($filter)==3) {
					$result[] = $this->convertFilter($filter[0],$filter[1],$filter[2]);
				}
			}
		}
		return $result;
	}

	protected function processFiltersParameter($tables,$satisfy,$filters) {
		$result = $this->convertFilters($filters);
		if (!$result) return array();
		$and = ($satisfy && strtolower($satisfy)=='any')?'or':'and';
		return array($tables[0]=>array($and=>$result));
	}

	protected function processPageParameter($page) {
		if (!$page) return false;
		$page = explode(',',$page,2);
		if (count($page)<2) $page[1]=20;
		$page[0] = ($page[0]-1)*$page[1];
		return $page;
	}

	protected function retrieveObject($key,$fields,$filters,$tables) {
		if (!$key) return false;
		$table = $tables[0];
		$sql = 'SELECT ';
		$sql .= '"'.implode('","',array_keys($fields[$table])).'"';
		$sql .= ' FROM "!"';
		$params = array($table);
		if (!isset($filters[$table])) $filters[$table] = array();
		if (!isset($filters[$table]['or'])) $filters[$table]['or'] = array();
		$filters[$table]['or'][] = array($key[1],'=',$key[0]);
		$this->addWhereFromFilters($filters[$table],$sql,$params);
		$object = null;
		if ($result = $this->db->query($sql,$params)) {
			$object = $this->db->fetchAssoc($result);
			foreach ($fields[$table] as $field) {
				if ($this->db->isBinaryType($field) && $object[$field->name]) {
					$object[$field->name] = $this->db->base64Encode($object[$field->name]);
				}
			}
			$this->db->close($result);
		}
		return $object;
	}

	protected function createObject($input,$tables) {
		if (!$input) return false;
		$input = (array)$input;
		$keys = implode('","',str_split(str_repeat('!', count($input))));
		$values = implode(',',str_split(str_repeat('?', count($input))));
		$params = array_merge(array_keys($input),array_values($input));
		array_unshift($params, $tables[0]);
		$result = $this->db->query('INSERT INTO "!" ("'.$keys.'") VALUES ('.$values.')',$params);
		if (!$result) return null;
		return $this->db->insertId($result);
	}

	protected function updateObject($key,$input,$filters,$tables) {
		if (!$input) return false;
		$input = (array)$input;
		$table = $tables[0];
		$sql = 'UPDATE "!" SET ';
		$params = array($table);
		foreach (array_keys($input) as $i=>$k) {
			if ($i) $sql .= ',';
			$v = $input[$k];
			$sql .= '"!"=?';
			$params[] = $k;
			$params[] = $v;
		}
		if (!isset($filters[$table])) $filters[$table] = array();
		if (!isset($filters[$table]['or'])) $filters[$table]['or'] = array();
		$filters[$table]['or'][] = array($key[1],'=',$key[0]);
		$this->addWhereFromFilters($filters[$table],$sql,$params);
		$result = $this->db->query($sql,$params);
		if (!$result) return null;
		return $this->db->affectedRows($result);
	}

	protected function deleteObject($key,$filters,$tables) {
		$table = $tables[0];
		$sql = 'DELETE FROM "!"';
		$params = array($table);
		if (!isset($filters[$table])) $filters[$table] = array();
		if (!isset($filters[$table]['or'])) $filters[$table]['or'] = array();
		$filters[$table]['or'][] = array($key[1],'=',$key[0]);
		$this->addWhereFromFilters($filters[$table],$sql,$params);
		$result = $this->db->query($sql,$params);
		if (!$result) return null;
		return $this->db->affectedRows($result);
	}

	protected function findRelations($tables,$database) {
		$tableset = array();
		$collect = array();
		$select = array();

		while (count($tables)>1) {
			$table0 = array_shift($tables);
			$tableset[] = $table0;

			$result = $this->db->query($this->db->getSql('reflect_belongs_to'),array($table0,$tables,$database,$database));
			while ($row = $this->db->fetchRow($result)) {
				$collect[$row[0]][$row[1]]=array();
				$select[$row[2]][$row[3]]=array($row[0],$row[1]);
				if (!in_array($row[0],$tableset)) $tableset[] = $row[0];
			}
			$result = $this->db->query($this->db->getSql('reflect_has_many'),array($tables,$table0,$database,$database));
			while ($row = $this->db->fetchRow($result)) {
				$collect[$row[2]][$row[3]]=array();
				$select[$row[0]][$row[1]]=array($row[2],$row[3]);
				if (!in_array($row[2],$tableset)) $tableset[] = $row[2];
			}
			$result = $this->db->query($this->db->getSql('reflect_habtm'),array($database,$database,$database,$database,$table0,$tables));
			while ($row = $this->db->fetchRow($result)) {
				$collect[$row[2]][$row[3]]=array();
				$select[$row[0]][$row[1]]=array($row[2],$row[3]);
				$collect[$row[4]][$row[5]]=array();
				$select[$row[6]][$row[7]]=array($row[4],$row[5]);
				if (!in_array($row[2],$tableset)) $tableset[] = $row[2];
				if (!in_array($row[4],$tableset)) $tableset[] = $row[4];
			}
		}
		$tableset[] = array_shift($tables);
		$tableset = array_unique($tableset);
		return array($tableset,$collect,$select);
	}

	protected function retrieveInput($post) {
		$input = (object)array();
		$data = trim(file_get_contents($post));
		if (strlen($data)>0) {
			if ($data[0]=='{') {
				$input = json_decode($data);
			} else {
				parse_str($data, $input);
				foreach ($input as $key => $value) {
					if (substr($key,-9)=='__is_null') {
						$input[substr($key,0,-9)] = null;
						unset($input[$key]);
					}
				}
				$input = (object)$input;
			}
		}
		return $input;
	}

	protected function addRelationColumns($columns,$select) {
		if ($columns) {
			foreach ($select as $table=>$keys) {
				foreach ($keys as $key=>$other) {
					$columns.=",$table.$key,".implode('.',$other);
				}
		  }
		}
		return $columns;
	}

	protected function findFields($tables,$columns,$database) {
		$fields = array();
		foreach ($tables as $i=>$table) {
			$fields[$table] = $this->findTableFields($table,$database);
			$fields[$table] = $this->filterFieldsByColumns($fields[$table],$columns,$i==0,$table);
		}
		return $fields;
	}

	protected function filterFieldsByColumns($fields,$columns,$first,$table) {
		if ($columns) {
			$columns = explode(',',$columns);
			foreach (array_keys($fields) as $key) {
				$delete = true;
				foreach ($columns as $column) {
					if (strpos($column,'.')) {
						if ($column=="$table.$key" || $column=="$table.*") {
							$delete = false;
						}
					} elseif ($first) {
						if ($column==$key || $column=="*") {
							$delete = false;
						}
					}
				}
				if ($delete) unset($fields[$key]);
			}
		}
		return $fields;
	}

	protected function findTableFields($table,$database) {
		$fields = array();
		foreach ($this->db->fetchFields($table) as $field) {
			$fields[$field->name] = $field;
		}
		return $fields;
	}

	protected function filterInputByFields($input,$fields) {
		if ($fields) foreach (array_keys((array)$input) as $key) {
			if (!isset($fields[$key])) {
				unset($input->$key);
			}
		}
		return $input;
	}

	protected function convertBinary(&$input,$keys) {
		foreach ($keys as $key=>$field) {
			if (isset($input->$key) && $input->$key && $this->db->isBinaryType($field)) {
				$data = $input->$key;
				$data = str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT);
				$input->$key = (object)array('type'=>'base64','data'=>$data);
			}
		}
	}

	protected function getParameters($settings) {
		extract($settings);

		$table     = $this->parseRequestParameter($request, 'a-zA-Z0-9\-_');
		$key       = $this->parseRequestParameter($request, 'a-zA-Z0-9\-_'); // auto-increment or uuid
		$action    = $this->mapMethodToAction($method,$key);
		$include   = $this->parseGetParameter($get, 'include', 'a-zA-Z0-9\-_,');
		$callback  = $this->parseGetParameter($get, 'callback', 'a-zA-Z0-9\-_');
		$page      = $this->parseGetParameter($get, 'page', '0-9,');
		$filters   = $this->parseGetParameterArray($get, 'filter', false);
		$satisfy   = $this->parseGetParameter($get, 'satisfy', 'a-zA-Z');
		$columns   = $this->parseGetParameter($get, 'columns', 'a-zA-Z0-9\-_,.*');
		$order     = $this->parseGetParameter($get, 'order', 'a-zA-Z0-9\-_,');
		$transform = $this->parseGetParameter($get, 'transform', 't1');

		$tables    = $this->processTableAndIncludeParameters($database,$table,$include,$action);
		$key       = $this->processKeyParameter($key,$tables,$database);
		$filters   = $this->processFiltersParameter($tables,$satisfy,$filters);
		$page      = $this->processPageParameter($page);
		$order     = $this->processOrderParameter($order);

		// reflection
		list($tables,$collect,$select) = $this->findRelations($tables,$database);
		$columns = $this->addRelationColumns($columns,$select);
		$fields = $this->findFields($tables,$columns,$database);

		// permissions
		if ($table_authorizer) $this->applyTableAuthorizer($table_authorizer,$action,$database,$tables);
		if (!isset($tables[0])) $this->exitWith404('entity');
		if ($record_filter) $this->applyRecordFilter($record_filter,$action,$database,$tables,$filters);
		if ($tenancy_function) $this->applyTenancyFunction($tenancy_function,$action,$database,$fields,$filters);
		if ($column_authorizer) $this->applyColumnAuthorizer($column_authorizer,$action,$database,$fields);

		if ($post) {
			// input
			$context = $this->retrieveInput($post);
			$input = $this->filterInputByFields($context,$fields[$tables[0]]);

			if ($tenancy_function) $this->applyInputTenancy($tenancy_function,$action,$database,$tables[0],$input,$fields[$tables[0]]);
			if ($input_sanitizer) $this->applyInputSanitizer($input_sanitizer,$action,$database,$tables[0],$input,$fields[$tables[0]]);
			if ($input_validator) $this->applyInputValidator($input_validator,$action,$database,$tables[0],$input,$fields[$tables[0]],$context);

			$this->convertBinary($input,$fields[$tables[0]]);
		}

		return compact('action','database','tables','key','callback','page','filters','fields','order','transform','input','collect','select');
	}

	protected function addWhereFromFilters($filters,&$sql,&$params) {
		$first = true;
		if (isset($filters['or'])) {
			$first = false;
			$sql .= ' WHERE (';
			foreach ($filters['or'] as $i=>$filter) {
				$sql .= $i==0?'':' OR ';
				$sql .= '"!" ! ?';
				$params[] = $filter[0];
				$params[] = $filter[1];
				$params[] = $filter[2];
			}
			$sql .= ')';
		}
		if (isset($filters['and'])) {
			foreach ($filters['and'] as $i=>$filter) {
				$sql .= $first?' WHERE ':' AND ';
				$sql .= '"!" ! ?';
				$params[] = $filter[0];
				$params[] = $filter[1];
				$params[] = $filter[2];
				$first = false;
			}
		}
	}

	protected function listCommandInternal($parameters) {
		extract($parameters);
		echo '{';
		$table = array_shift($tables);
		// first table
		$count = false;
		echo '"'.$table.'":{';
		if (is_array($order) && is_array($page)) {
			$params = array();
			$sql = 'SELECT COUNT(*) FROM "!"';
			$params[] = $table;
			if (isset($filters[$table])) {
					$this->addWhereFromFilters($filters[$table],$sql,$params);
			}
			if ($result = $this->db->query($sql,$params)) {
				while ($pages = $this->db->fetchRow($result)) {
					$count = $pages[0];
				}
			}
		}
		$params = array();
		$sql = 'SELECT ';
		$sql .= '"'.implode('","',array_keys($fields[$table])).'"';
		$sql .= ' FROM "!"';
		$params[] = $table;
		if (isset($filters[$table])) {
				$this->addWhereFromFilters($filters[$table],$sql,$params);
		}
		if (is_array($order)) {
			$sql .= ' ORDER BY "!" !';
			$params[] = $order[0];
			$params[] = $order[1];
		}
		if (is_array($order) && is_array($page)) {
			$sql = $this->db->addLimitToSql($sql,$page[1],$page[0]);
		}
		if ($result = $this->db->query($sql,$params)) {
			echo '"columns":';
			$keys = array();
			$base64 = array();
			foreach ($fields[$table] as $field) {
				$base64[] = $this->db->isBinaryType($field);
				$keys[] = $field->name;
			}
			echo json_encode($keys);
			$keys = array_flip($keys);
			echo ',"records":[';
			$first_row = true;
			while ($row = $this->db->fetchRow($result)) {
				if ($first_row) $first_row = false;
				else echo ',';
				if (isset($collect[$table])) {
					foreach (array_keys($collect[$table]) as $field) {
						$collect[$table][$field][] = $row[$keys[$field]];
					}
				}
				foreach ($base64 as $k=>$v) {
					if ($v && $row[$k]) {
						$row[$k] = $this->db->base64Encode($row[$k]);
					}
				}
				echo json_encode($row);
			}
			$this->db->close($result);
			echo ']';
			if ($count) echo ',';
		}
		if ($count) echo '"results":'.$count;
		echo '}';
		// other tables
		foreach ($tables as $t=>$table) {
			echo ',';
			echo '"'.$table.'":{';
			$params = array();
			$sql = 'SELECT ';
			$sql .= '"'.implode('","',array_keys($fields[$table])).'"';
			$sql .= ' FROM "!"';
			$params[] = $table;
			if (isset($select[$table])) {
				echo '"relations":{';
				$first_row = true;
				foreach ($select[$table] as $field => $path) {
					$values = $collect[$path[0]][$path[1]];
					if (!isset($filters[$table])) $filters[$table] = array();
					if (!isset($filters[$table]['or'])) $filters[$table]['or'] = array();
					$filters[$table]['or'][] = array($field,'IN',$values);
					if ($first_row) $first_row = false;
					else echo ',';
					echo '"'.$field.'":"'.implode('.',$path).'"';
				}
				echo '}';
				$this->addWhereFromFilters($filters[$table],$sql,$params);
			}
			if ($result = $this->db->query($sql,$params)) {
				if (isset($select[$table])) echo ',';
				echo '"columns":';
				$keys = array();
				$base64 = array();
				foreach ($fields[$table] as $field) {
					$base64[] = $this->db->isBinaryType($field);
					$keys[] = $field->name;
				}
				echo json_encode($keys);
				$keys = array_flip($keys);
				echo ',"records":[';
				$first_row = true;
				while ($row = $this->db->fetchRow($result)) {
					if ($first_row) $first_row = false;
					else echo ',';
					if (isset($collect[$table])) {
						foreach (array_keys($collect[$table]) as $field) {
							$collect[$table][$field][]=$row[$keys[$field]];
						}
					}
					foreach ($base64 as $k=>$v) {
						if ($v && $row[$k]) {
							$row[$k] = $this->db->base64Encode($row[$k]);
						}
					}
					echo json_encode($row);
				}
				$this->db->close($result);
				echo ']';
			}
			echo '}';
		}
		echo '}';
	}

	protected function readCommand($parameters) {
		extract($parameters);
		$object = $this->retrieveObject($key,$fields,$filters,$tables);
		if (!$object) $this->exitWith404('object');
		$this->startOutput($callback);
		echo json_encode($object);
		$this->endOutput($callback);
	}

	protected function createCommand($parameters) {
		extract($parameters);
		if (!$input) $this->exitWith404('input');
		$this->startOutput($callback);
		echo json_encode($this->createObject($input,$tables));
		$this->endOutput($callback);
	}

	protected function updateCommand($parameters) {
		extract($parameters);
		if (!$input) $this->exitWith404('subject');
		$this->startOutput($callback);
		echo json_encode($this->updateObject($key,$input,$filters,$tables));
		$this->endOutput($callback);
	}

	protected function deleteCommand($parameters) {
		extract($parameters);
		$this->startOutput($callback);
		echo json_encode($this->deleteObject($key,$filters,$tables));
		$this->endOutput($callback);
	}

	protected function listCommand($parameters) {
		extract($parameters);
		$this->startOutput($callback);
		if ($transform) {
			ob_start();
		}
		$this->listCommandInternal($parameters);
		if ($transform) {
			$content = ob_get_contents();
			ob_end_clean();
			$data = json_decode($content,true);
			echo json_encode(self::php_crud_api_transform($data));
		}
		$this->endOutput($callback);
	}

	public function __construct($config) {
		extract($config);

		// initialize
		$dbengine = isset($dbengine)?$dbengine:null;
		$hostname = isset($hostname)?$hostname:null;
		$username = isset($username)?$username:null;
		$password = isset($password)?$password:null;
		$database = isset($database)?$database:null;
		$port = isset($port)?$port:null;
		$socket = isset($socket)?$socket:null;
		$charset = isset($charset)?$charset:null;

		$table_authorizer = isset($table_authorizer)?$table_authorizer:null;
		$record_filter = isset($record_filter)?$record_filter:null;
		$column_authorizer = isset($column_authorizer)?$column_authorizer:null;
		$tenancy_function = isset($tenancy_function)?$tenancy_function:null;
		$input_sanitizer = isset($input_sanitizer)?$input_sanitizer:null;
		$input_validator = isset($input_validator)?$input_validator:null;

		$db = isset($db)?$db:null;
		$method = isset($method)?$method:null;
		$request = isset($request)?$request:null;
		$get = isset($get)?$get:null;
		$post = isset($post)?$post:null;

		// defaults
		if (!$dbengine) {
			$dbengine = 'MySQL';
		}
		if (!$method) {
			$method = $_SERVER['REQUEST_METHOD'];
		}
		if (!$request) {
			$request = isset($_SERVER['PATH_INFO'])?$_SERVER['PATH_INFO']:'';
			if (!$request) {
				$request = isset($_SERVER['ORIG_PATH_INFO'])?$_SERVER['ORIG_PATH_INFO']:'';
			}
		}
		if (!$get) {
			$get = $_GET;
		}
		if (!$post) {
			$post = 'php://input';
		}

		// connect
		$request = trim($request,'/');
		if (!$database) {
			$database  = $this->parseRequestParameter($request, 'a-zA-Z0-9\-_');
		}
		if (!$db) {
			$db = new $dbengine();
			if (!$charset) {
				$charset = $db->getDefaultCharset();
			}
			$db->connect($hostname,$username,$password,$database,$port,$socket,$charset);
		}

		$this->db = $db;
		$this->settings = compact('method', 'request', 'get', 'post', 'database', 'table_authorizer', 'record_filter', 'column_authorizer', 'tenancy_function', 'input_sanitizer', 'input_validator');
	}

	public static function php_crud_api_transform(&$tables) {
		$get_objects = function (&$tables,$table_name,$where_index=false,$match_value=false) use (&$get_objects) {
			$objects = array();
			foreach ($tables[$table_name]['records'] as $record) {
				if ($where_index===false || $record[$where_index]==$match_value) {
					$object = array();
					foreach ($tables[$table_name]['columns'] as $index=>$column) {
						$object[$column] = $record[$index];
						foreach ($tables as $relation=>$reltable) {
							if (isset($reltable['relations'])) {
								foreach ($reltable['relations'] as $key=>$target) {
									if ($target == "$table_name.$column") {
										$column_indices = array_flip($reltable['columns']);
										$object[$relation] = $get_objects($tables,$relation,$column_indices[$key],$record[$index]);
									}
								}
							}
						}
					}
					$objects[] = $object;
				}
			}
			return $objects;
		};
		$tree = array();
		foreach ($tables as $name=>$table) {
			if (!isset($table['relations'])) {
				$tree[$name] = $get_objects($tables,$name);
				if (isset($table['results'])) {
					$tree['_results'] = $table['results'];
				}
			}
		}
		return $tree;
	}

	protected function swagger($settings) {
		extract($settings);

		$tables = array();
		if ($result = $this->db->query($this->db->getSql('list_tables'),array($database))) {
			while ($row = $this->db->fetchRow($result)) {
				$table = array(
					'name'=>$row[0],
					'comments'=>$row[1],
					'root_actions'=>array(
						array('name'=>'list','method'=>'get'),
						array('name'=>'create','method'=>'post'),
					),
					'id_actions'=>array(
						array('name'=>'read','method'=>'get'),
						array('name'=>'update','method'=>'put'),
						array('name'=>'delete','method'=>'delete'),
					),
				);
				$tables[] = $table;
			}
			$this->db->close($result);
		}

		foreach ($tables as $t=>$table)	{
			foreach (array('root_actions','id_actions') as $path) {
				foreach ($table[$path] as $i=>$action) {
					$table_list = array($table['name']);
					$fields = $this->findFields($table_list,false,$database);
					if ($table_authorizer) $this->applyTableAuthorizer($table_authorizer,$action['name'],$database,$table_list);
					if ($column_authorizer) $this->applyColumnAuthorizer($column_authorizer,$action['name'],$database,$fields);
					if (!$table_list || !$fields[$table['name']]) $tables[$t][$path][$i] = false;
					else $tables[$t][$path][$i]['fields'] = $fields[$table['name']];
				}
				// remove unauthorized tables and tables without fields
				$tables[$t][$path] = array_values(array_filter($tables[$t][$path]));
			}
			if (!$tables[$t]['root_actions']&&!$tables[$t]['id_actions']) $tables[$t] = false;
		}
		$tables = array_merge(array_filter($tables));
		//var_dump($tables);die();

		header('Content-Type: application/json; charset=utf-8');
		echo '{"swagger":"2.0",';
		echo '"info":{';
		echo '"title":"'.$database.'",';
		echo '"description":"API generated with [PHP_CRUD_API](https://github.com/mevdschee/php-crud-api)",';
		echo '"version":"1.0.0"';
		echo '},';
		echo '"host":"'.$_SERVER['HTTP_HOST'].'",';
		echo '"basePath":"'.$_SERVER['SCRIPT_NAME'].'",';
		echo '"schemes":["http'.((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'s':'').'"],';
		echo '"consumes":["application/json"],';
		echo '"produces":["application/json"],';
		echo '"tags":[';
		foreach ($tables as $i=>$table) {
			if ($i>0) echo ',';
			echo '{';
			echo '"name":"'.$table['name'].'",';
			echo '"description":"'.$table['comments'].'"';
			echo '}';
		}
		echo '],';
		echo '"paths":{';
		foreach ($tables as $i=>$table) {
			if ($table['root_actions']) {
				if ($i>0) echo ',';
				echo '"/'.$table['name'].'":{';
				foreach ($table['root_actions'] as $j=>$action) {
					if ($j>0) echo ',';
					echo '"'.$action['method'].'":{';
					echo '"tags":["'.$table['name'].'"],';
					echo '"summary":"'.ucfirst($action['name']).'",';
					if ($action['name']=='list') {
						echo '"parameters":[';
						echo '{';
						echo '"name":"include",';
						echo '"in":"query",';
						echo '"description":"One or more related entities (comma separated).",';
						echo '"required":false,';
						echo '"type":"string"';
						echo '},';
						echo '{';
						echo '"name":"order",';
						echo '"in":"query",';
						echo '"description":"Column you want to sort on and the sort direction (comma separated). Example: id,desc",';
						echo '"required":false,';
						echo '"type":"string"';
						echo '},';
						echo '{';
						echo '"name":"page",';
						echo '"in":"query",';
						echo '"description":"Page number and page size (comma separated). NB: You cannot use \"page\" without \"order\"! Example: 1,10",';
						echo '"required":false,';
						echo '"type":"string"';
						echo '},';
						echo '{';
						echo '"name":"transform",';
						echo '"in":"query",';
						echo '"description":"Transform the records to object format. NB: This can also be done client-side in JavaScript!",';
						echo '"required":false,';
						echo '"type":"boolean"';
						echo '},';
						echo '{';
						echo '"name":"columns",';
						echo '"in":"query",';
						echo '"description":"The table columns you want to retrieve (comma separated). Example: posts.*,categories.name",';
						echo '"required":false,';
						echo '"type":"string"';
						echo '},';
						echo '{';
						echo '"name":"filter[]",';
						echo '"in":"query",';
						echo '"description":"Filters to be applied. Each filter consists of a column, an operator and a value (comma separated). Example: id,eq,1",';
						echo '"required":false,';
						echo '"type":"array",';
						echo '"collectionFormat":"multi",';
						echo '"items":{"type":"string"}';
						echo '},';
						echo '{';
						echo '"name":"satisfy",';
						echo '"in":"query",';
						echo '"description":"Should all filters match (default)? Or any?",';
						echo '"required":false,';
						echo '"type":"string",';
						echo '"enum":["any"]';
						echo '},';
						echo '{';
						echo '"name":"callback",';
						echo '"in":"query",';
						echo '"description":"JSONP callback function name",';
						echo '"required":false,';
						echo '"type":"string"';
						echo '}';
						echo '],';
						echo '"responses":{';
						echo '"200":{';
						echo '"description":"An array of '.$table['name'].'",';
						echo '"schema":{';
						echo '"type":"array",';
						echo '"items":{';
						echo '"type": "object",';
						echo '"properties": {';
						foreach (array_keys($action['fields']) as $k=>$field) {
							if ($k>0) echo ',';
							echo '"'.$field.'": {';
							echo '"type": "string"';
							echo '}';
						}
						echo '}'; //properties
						echo '}'; //items
						echo '}'; //schema
						echo '}'; //200
						echo '}'; //responses
					}
					if ($action['name']=='create') {
						echo '"parameters":[{';
						echo '"name":"item",';
						echo '"in":"body",';
						echo '"description":"Item to create.",';
						echo '"required":false,';
						echo '"schema":{';
						echo '"type": "object",';
						echo '"properties": {';
						foreach (array_keys($action['fields']) as $k=>$field) {
							if ($k>0) echo ',';
							echo '"'.$field.'": {';
							echo '"type": "string"';
							echo '}';
						}
						echo '}'; //properties
						echo '}'; //schema
						echo '}],';
						echo '"responses":{';
						echo '"200":{';
						echo '"description":"Identifier of created item.",';
						echo '"schema":{';
						echo '"type":"integer"';
						echo '}';//schema
						echo '}';//200
						echo '}';//responses
					}
					echo '}';//method
				}
				echo '}';
			}
			if ($table['id_actions']) {
				if ($i>0 || $table['root_actions']) echo ',';
				echo '"/'.$table['name'].'/{id}":{';
				foreach ($table['id_actions'] as $j=>$action) {
					if ($j>0) echo ',';
					echo '"'.$action['method'].'":{';
					echo '"tags":["'.$table['name'].'"],';
					echo '"summary":"'.ucfirst($action['name']).'",';
					echo '"parameters":[';
					echo '{';
					echo '"name":"id",';
					echo '"in":"path",';
					echo '"description":"Identifier for item.",';
					echo '"required":true,';
					echo '"type":"string"';
					echo '}';
					if ($action['name']=='update') {
						echo ',{';
						echo '"name":"item",';
						echo '"in":"body",';
						echo '"description":"Properties of item to update.",';
						echo '"required":false,';
						echo '"schema":{';
						echo '"type": "object",';
						echo '"properties": {';
						foreach (array_keys($action['fields']) as $k=>$field) {
							if ($k>0) echo ',';
							echo '"'.$field.'": {';
							echo '"type": "string"';
							echo '}';
						}
						echo '}'; //properties
						echo '}'; //schema
						echo '}';
					}
					echo '],';
					if ($action['name']=='read') {
						echo '"responses":{';
						echo '"200":{';
						echo '"description":"The requested item.",';
						echo '"schema":{';
						echo '"type": "object",';
						echo '"properties": {';
						foreach (array_keys($action['fields']) as $k=>$field) {
							if ($k>0) echo ',';
							echo '"'.$field.'": {';
							echo '"type": "string"';
							echo '}';
						}
						echo '}'; //properties
						echo '}'; //schema
						echo '}';
						echo '}';
					} else {
						echo '"responses":{';
						echo '"200":{';
						echo '"description":"Number of affected rows.",';
						echo '"schema":{';
						echo '"type":"integer"';
						echo '}';
						echo '}';
						echo '}';
					}
					echo '}';
				}
				echo '}';
			}
		}
		echo '}';
			echo '}';
	}

	public function executeCommand() {
		if (isset($_SERVER['REQUEST_METHOD'])) {
			header('Access-Control-Allow-Origin: *');
		}
		if (!$this->settings['request']) {
			$this->swagger($this->settings);
		} else {
			$parameters = $this->getParameters($this->settings);
			switch($parameters['action']){
				case 'list': $this->listCommand($parameters); break;
				case 'read': $this->readCommand($parameters); break;
				case 'create': $this->createCommand($parameters); break;
				case 'update': $this->updateCommand($parameters); break;
				case 'delete': $this->deleteCommand($parameters); break;
				case 'headers': $this->headersCommand($parameters); break;
			}
		}
	}

}

// uncomment the lines below when running in stand-alone mode:

$api = new PHP_CRUD_API(array(
	'dbengine'=>'MySQL',
	'hostname'=>'localhost',
	'username'=>'root',
	'password'=>'',
	'database'=>'orgtree',
	'charset'=>'utf8'
));
$api->executeCommand();

// For Microsoft SQL Server 2012 use:

// $api = new PHP_CRUD_API(array(
// 	'dbengine'=>'SQLServer',
// 	'hostname'=>'(local)',
// 	'username'=>'',
// 	'password'=>'',
// 	'database'=>'xxx',
// 	'charset'=>'UTF-8'
// ));
// $api->executeCommand();

// For PostgreSQL 9 use:

// $api = new PHP_CRUD_API(array(
// 	'dbengine'=>'PostgreSQL',
// 	'hostname'=>'localhost',
// 	'username'=>'xxx',
// 	'password'=>'xxx',
// 	'database'=>'xxx',
// 	'charset'=>'UTF8'
// ));
// $api->executeCommand();

// For SQLite 3 use:

// $api = new PHP_CRUD_API(array(
// 	'dbengine'=>'SQLite',
// 	'database'=>'data/blog.db',
// ));
// $api->executeCommand();
