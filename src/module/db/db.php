<?php
/**
 * php-az-db - db.php
 * minimal, low-ceremony, macro-driven:
 * database access layer, active record style, query builder.
 * 
 * @author Marc Masip Marín <marc@azestudio.net>
 */
namespace db;

function desc(){
    return [
        "title"=>"Data Base Active Record Tools",
        "desc"=>"Structures to manage database rows with an active record style and raw SQL",
        "ver"=>"3.0.0",
        "jsauth"=>true
    ];
}

/** php-ar by marc at azestudio.net & co. (PHP 7.X Compatible ver.) **/
class expr{ function __construct(public $v) { }}

class db {
    private static $conn = null;
	
	static $autoinit=true;
	static $queries = [];
	static $qt0 = 0;
	static $qn = 0;
	
	static function expr(string $v): expr { 
        return new expr($v); 
    }
    
    static function ready(){//test without initialize
        return static::$conn!=false;
    }
	
	
    static function init(string $host, string $user, string $pass, string $name): void {
        if (self::$conn) return;
         //  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
		//error_log("E $host NAME=$name");
        self::$conn = mysqli_connect($host, $user, $pass, $name);
        mysqli_set_charset(self::$conn, 'utf8mb4');
    }

    static function conn(): \mysqli {
        if(self::$conn) return self::$conn;
        throw new \RuntimeException("db: not initialized");
    }

    static function query(string $sql, array $bind = []) {
		
		if(self::$autoinit && !self::$conn){
           
			self::init( conf("db_host"),conf("db_user"),conf("db_pass"),conf("db_name") );
		}
		
		$t0 = microtime(true);
        $stmt = mysqli_prepare(self::conn(), $sql);
        if ($bind) {
            $types = implode('', array_map(function($v) {
                if (is_int($v)) return 'i';
                if (is_float($v)) return 'd';
                return 's';
            }, $bind));
            mysqli_stmt_bind_param($stmt, $types, ...$bind);
        }
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt) ?: true;
        mysqli_stmt_close($stmt);
		$td = microtime(true) - $t0;
		if(!static::$qt0)
		static::$qt0 = microtime(true);
		static::$qn++;
		static::$queries[] = [ $sql, $td ];
        return $result;
    }

	
	static function report_log($m=""){
		
		$str="";
		$qn=0;
		$t=0;
		foreach(self::$queries as $q){
			$str.= $q[0]." ".$q[1];
			$t+= floatval($q[0]);
			$qn++;
		}
		$mem = memory_get_usage();
		$qps = round(($t)/$qn,3);
		
		log::trace("$m db queries=$qn t=$t qps=$qps mem=$mem");
		self::$queries = [];
	}
	
    static function exec(string $sql, array $bind = []): int {
        self::query($sql, $bind);
        return mysqli_affected_rows(self::conn());
    }

    static function lastid(): int  { return mysqli_insert_id(self::conn()); }
    static function begin(): void  { mysqli_begin_transaction(self::conn()); }
    static function commit(): void { mysqli_commit(self::conn()); }
    static function rollback(): void { mysqli_rollback(self::conn()); }

    static function fetch($q, $as = null,$bind=[]) {
        if ($q instanceof sel) {
            $sql = $q->sql();
            $bind = $q->bind;
            $cls = $q->class();
        } else {
            $sql = $q;
          
            $cls = null;
        }
        return new rows(self::query($sql, $bind), $as ?: $cls);
    }
}

class sel {
    public $bind = [];
    public $alias = null;
    private $from;
    private $cls = null;
    private $cols = ['*'];
    private $where = [];
    private $join = [];
    private $group = [];
    private $order = [];
    private $lim = 0;
    private $off = 0;
    
    public function __construct($from) {
        if ($from instanceof sel) {
            $this->init_sub($from);
        } elseif (is_string($from) && class_exists($from) && defined("$from::TBL")) {
            $this->init_cls($from);
        } else {
            $this->from =  $from;
        }
    }

    private function init_sub(sel $sub): void {
        $alias = $sub->alias ? $sub->alias : 'sub_' . uniqid();
        $this->from = "({$sub->sql()}) AS `$alias`";
        $this->bind  = $sub->bind;
    }

    private function init_cls(string $cls): void {
        $this->from = "`".$cls::TBL."`";
        $this->cls  = $cls;
    }

    function class(): ?string { return $this->cls; }

    function as(string $alias) { $this->alias = $alias; return $this; }
    function select(string ...$cols) { $this->cols = $cols; return $this; }
    function col( string $col ){ $this->cols[] = $col; }
    function join(string $expr)  { $this->join[]  = $expr; return $this; }
    function group(string $expr) { $this->group[] = $expr; return $this; }
    function order(string $expr) { $this->order[] = $expr; return $this; }
    function limit(int $n, int $off = 0) { $this->lim = $n; $this->off = $off; return $this; }
	
    function whereFk(ar $obj) {
        return $this->where("`fk_".$obj::TBL."` = ?", $obj->id());
    }
	

    function where( $expr, ...$vals) {
		
		if ($expr instanceof \db\ar || $expr instanceof \db\rows || $expr instanceof \db\sel) {
            $cls = $expr instanceof \db\ar ? get_class($expr) : $expr->class();
            if (!$cls) throw new \InvalidArgumentException("No se puede inferir la FK: clase desconocida.");
            
            $fk_col = "fk_" . $cls::TBL;
            
            if ($expr instanceof \db\rows) {
                $ids = $expr->ids();
                if (empty($ids)) return new selempty();
                $expr = "`$fk_col` IN (?)";
                $vals = [$ids];
            } elseif ($expr instanceof \db\sel) {
                if ($expr instanceof selempty) return new selempty($this->from); // Cortocircuito si la subquery ya venía vacía
                $expr = "`$fk_col` IN (?)";
                $vals = [$expr];
            } else {
                $vals = [$expr->id()];
                $expr = "`$fk_col` = ?";
              
            }
        }

        if (!$vals && is_string($expr)) { 
            $this->where[] = $expr; return $this; 
        }

        if (substr_count($expr, '?') !== count($vals)) {
            throw new \InvalidArgumentException("sel: placeholder mismatch in: $expr");
        }

        $parts = explode('?', $expr);
        $out   = '';
        $extra = [];

        foreach ($vals as $i => $val) {
            $out .= $parts[$i];

            if ($val instanceof \db\rows) {
                $val = $val->ids();
            } elseif ($val instanceof \db\ar) {
                $val = $val->id();
            }

            if ($val instanceof \db\sel) {

                if ($val instanceof \db\selempty) return $val; 

                $sub = clone $val; 
              
                if ($sub->cols === ['*'] && $sub->class()) {
                    $pk = defined($sub->class() . '::PK') ? $sub->class()::PK : 'id';
                    $sub->select("`$pk`");
                }

                // Autocorrección de "=" a "IN"
                if (preg_match('/=\s*$/', $out)) {
                    $out = preg_replace('/=\s*$/', 'IN ', $out); 
                }

                $out .= "(" . $sub->sql() . ")"; // Envuelve el SQL de la subconsulta
                array_push($extra, ...$sub->bind);

            } elseif (is_array($val)) {
                if (empty($val))  return new selempty($this->from);

                $replaced = false;
                if (preg_match('/=\s*$/', $out)) {
                    $out = preg_replace('/=\s*$/', 'IN (', $out);
                    $replaced = true;
                }

                $val = array_map(fn($v) => $v instanceof \db\ar ? $v->id() : $v, $val);
                $out .= implode(',', array_fill(0, count($val), '?'));
                if ($replaced) $out .= ')';
                
                array_push($extra, ...array_values($val));

            } else {
                $out .= '?';
                $extra[] = $val;
            }
        }

        $this->where[] = $out . end($parts);
        
        if (count($extra)) array_push($this->bind, ...$extra);
        
        return $this;
    }

    function sql(): string {
        $sql = 'SELECT ' . implode(',', $this->cols) . ' FROM ' . $this->from;
        if ($this->join)  $sql .= ' ' . implode(' ', $this->join);
        if ($this->where) $sql .= ' WHERE ' . implode(' AND ', $this->where);
        if ($this->group) $sql .= ' GROUP BY ' . implode(',', $this->group);
        if ($this->order) $sql .= ' ORDER BY ' . implode(',', $this->order);
        if ($this->lim)   $sql .= " LIMIT {$this->lim}";
        if ($this->off)   $sql .= " OFFSET {$this->off}";
        return $sql;
    }

    function __toString(): string { return $this->sql(); }

    function fetch(?string $as = null) { return db::fetch($this,  $as ? $as : $this->cls); }
    function first(?string $as = null) { return $this->limit(1)->fetch($as)->first(); }

    function count(): int {
        $c = clone $this;
        $c->select('COUNT(1) as c');
        $c->order = []; $c->lim = 0; $c->off = 0;
        $first = $c->first();
        return (int)($first ? $first->c : 0);
    }
    
    function update(array $data): int {
        if (empty($data)) return 0;

        $set = [];$set_bind = [];

        foreach ($data as $col =>$val) {
            if ($val instanceof \db\expr) {$set[] = "`$col` = {$val->v}";
            } else {
                $set[] = "`$col` = ?";
                $set_bind[] =$val;
            }
        }

        $sql = "UPDATE {$this->from} SET " . implode(', ', $set);
        
        if ($this->where) {
            $sql .= ' WHERE ' . implode(' AND ', $this->where);
        }

        $bind = array_merge($set_bind,$this->bind);

        return db::exec($sql,$bind);
    }

    function del(): int {
        $sql = "DELETE FROM {$this->from}";
        if ($this->where) $sql .= ' WHERE ' . implode(' AND ', $this->where);
        return db::exec($sql, $this->bind);
    }
}

class rows implements \Iterator, \Countable, \ArrayAccess{
    private const STRONG_LIMIT = 100;

    private $res;
    private $cls;
    private $n;
    private $pos = 0;

    private $pool = null;
    private $strong = [];
    private $weak   = [];

    function __construct($result, ?string $cls = null) {
        $this->res = $result instanceof \mysqli_result ? $result : null;
        $this->cls = $cls;
        $this->n   = $this->res ? mysqli_num_rows($this->res) : 0;
    }
    
    public function class(): ?string { 
        return $this->cls; 
    }

    function __destruct() { $this->free(); }

    private function free(): void {
        if ($this->res) { mysqli_free_result($this->res); $this->res = null; }
        $this->strong = [];
        $this->weak   = [];
    }

    private function hydrate(array $data) {
        $cls = $this->cls ? $this->cls : ar::class;
        return new $cls($data, true);
    }

    private function load(): void {
        if ($this->pool !== null) return;
        $this->pool = [];
        if (!$this->res) return;
        mysqli_data_seek($this->res, 0);
        while ($row = mysqli_fetch_assoc($this->res))
            $this->pool[] = $this->hydrate($row);
        $this->free();
    }

    public function each(): \Generator{
        if ($this->res) {
            mysqli_data_seek($this->res, 0);
            while ($data = mysqli_fetch_assoc($this->res))
                yield $this->hydrate($data);
            $this->free();
        }
    }

    private function at(int $i) {
        if ($this->pool !== null) return isset($this->pool[$i]) ? $this->pool[$i] : null;
        if (!$this->res) return null;

        if (isset($this->weak[$i]) && class_exists('\WeakReference') && $this->weak[$i] instanceof \WeakReference && $row = $this->weak[$i]->get()) {
            return $row;
        }

        mysqli_data_seek($this->res, $i);
        if (!($data = mysqli_fetch_assoc($this->res))) return null;

        $row = $this->hydrate($data);
        if (class_exists('\WeakReference')) {
            $this->weak[$i] = \WeakReference::create($row);
        }
        $this->strong[$i] = $row;
        if (count($this->strong) > self::STRONG_LIMIT) array_shift($this->strong);
        return $row;
    }

    function first()   { return $this->n ? $this->at(0) : null; }
    function all(): array   { $this->load(); return $this->pool; }
    function arr(): array   { return array_map(function($r) { return $r->arr(); }, $this->all()); }
    function arrmap(?string $key = null): array   { $result = [];
         $this->load();
        $pk = defined($this->cls . '::PK') ? $this->cls::PK : null;
        $key = $key ? $key : $pk;
        foreach ($this->all() as $r) $result[$r->$key] = $r->arr();
        return $result;
      }
    function col(string $key): array {
        if ($this->pool !== null) {
            $column = array_column($this->pool, $key);
        } else {
            $column = array_map(function($r) use ($key) { return $r->$key; }, iterator_to_array($this));
        }
        return array_values(array_unique(array_filter($column)));
    }

    function ids(): array {
        $pk = defined($this->cls . '::PK') ? $this->cls::PK : null;
        if (!$pk) throw new \Exception("rows: ids() needs a class");
        return $this->col($pk);
    }

    function map(?string $key = null): array {
        $this->load();
        $pk = defined($this->cls . '::PK') ? $this->cls::PK : null;
        $key = $key ? $key : $pk;
        if (!$key) throw new \Exception("rows: map() needs a key");
        return array_column($this->pool, null, $key);
    }

    function maplist(string $key): array {
        $result = [];
        foreach ($this->all() as $r) $result[$r->$key][] = $r->arr();
        return $result;
    }
    
    function as_items(){
        $result = [];
        foreach ($this->all() as $r) $result[] = $r->as_item();
        return $result;
    }
    function as_items_map($col="id"){
        $result = [];
        foreach ($this->all() as $r) {
            $i = $r->as_item();
            $result[$r[$col]]=$i;
        }
        return $result;
    }
    function as_items_maplist($col="id"){
        $result = [];
        foreach ($this->all() as $r) {
            $i = $r->as_item();
            $result[$r[$col]][]=$i;
        }
        return $result;
    }

    function refs(string $cls, string $fk = null) {
        $fk = $fk ? $fk : "fk_".$cls::TBL;
        $ids = $this->col($fk);
        return $ids ? $cls::sel()->where("`$fk` IN (?)", $ids)->fetch() : new rows(false, $cls);
    }

    function rels(string $cls, string $fk = null) {
        $fk = $fk ? $fk : "fk_".$this->cls::TBL;
        $ids = $this->ids();
        return $ids ? $cls::sel()->where("`$fk` IN (?)", $ids)->fetch() : new rows(false, $cls);
    }

    // Iterator
    function rewind(): void  { $this->pos = 0; }
    function key(): int      { return $this->pos; }
    function next(): void    { $this->pos++; }
    function current()       { return $this->at($this->pos); }
    function valid(): bool   { return $this->pos < $this->n; }
    function count(): int    { return $this->n; }

    // ArrayAccess
    function offsetExists($i): bool  { return is_int($i) && $i >= 0 && $i < $this->n; }
    function offsetGet($i)           { return $this->at($i); }
    function offsetSet($i, $v): void { throw new \Exception("rows is read-only"); }
    function offsetUnset($i): void   { throw new \Exception("rows is read-only"); }
}


class rowsempty extends rows{
	   // Iterator
    function rewind(): void  {  }
    function key(): int      { return 0; }
    function next(): void    {  }
    function current()       { return null; }
    function valid(): bool   { return false; }
    function count(): int    { return 0; }
    
    function ids(): array {
      return [];
    }

    // ArrayAccess
    function offsetExists($i): bool  { return false; }
    function offsetGet($i)           { return null; }
    function offsetSet($i, $v): void { throw new \Exception("rows is read-only"); }
    function offsetUnset($i): void   { throw new \Exception("rows is read-only"); }
	
}

class selempty extends sel {
    function fetch(?string $as = null) { 
        return new rowsempty($this, $as ?: $this->class());  
    }
    function count(): int {  return 0;  }
    function first(?string $as = null) {   return null; }
    function del(): int { return 0;  }
}

class ar implements \ArrayAccess{
    const TBL = '';
    const PK  = 'id';
    
    static $strict=true;

    protected $_data  = [];
    protected $_dirty = [];
    protected $_new   = true;

    function __construct(array $data = [], bool $fromdb = false)  {
        $this->_data = $data;
        $this->_new  = !$fromdb;
    }

    function __get(string $k)  { return isset($this->_dirty[$k]) ? $this->_dirty[$k] : (isset($this->_data[$k]) ? $this->_data[$k] : null); }
    function __set(string $k, $v): void { $this->_dirty[$k] = $v; }
    function __isset(string $k): bool { return isset($this->_dirty[$k]) || isset($this->_data[$k]); }

    function id() {
		if (is_array(static::PK)) {
			$keys = [];
			foreach (static::PK as $k) {
				$keys[$k] = $this->$k;
			}
			return $keys;
		}
		return $this->{static::PK};
	}
    function arr(): array  { return array_merge($this->_data, $this->_dirty); }
    function dirty(): array { return $this->_dirty; }
    function clean(): bool  { return empty($this->_dirty); }
    function is_new(): bool { return $this->_new; }

    static function sel()         { return new sel(static::class); }
    static function where($expr, ...$vals) { return static::sel()->where($expr, ...$vals); }
	static function find($args,$ex=false) {
        if (empty($args)){
            if($ex)err("invalid_id");
            return null;
        }
        $r = static::where('`' . static::PK . '` = ?', $args)->first();
        if(!$r && $ex) err("invalid_id");
        return $r;
    }
   
    static function all()         { return static::sel()->fetch(); }

    function rels(string $cls, string $fk = null) {
        $fk = $fk ? $fk : "fk_" . static::TBL;
        return $cls::sel()->where('`' . $fk . '` = ?', $this->id());
    }
	
	function ref($m){
		if($m)
		$this->{ "fk_".$m::TBL } = $m->id();
				return $this;
	}

    function refs(string $cls, string $fk = null) {
        $field = $fk ? $fk : "fk_".$cls::TBL;
        $fk_val = $this->{$field};
        return $fk_val ? $cls::find($fk_val) : null;
    }

	function save() {
		
		if (method_exists($this, 'save_before')) {
			$this->save_before();
		}
      
	  if (empty(array_keys($this->_dirty))) return $this;

	  $is_composite = is_array(static::PK);

	  if ($this->_new) {
		  $cols = [];
		  $vals = [];
		  $bind_values = [];

		  foreach ($this->_dirty as $k => $v) {
			  $cols[] = "`$k`";
			  if ($v instanceof expr) {
				  $vals[] = $v->v; 
			  } else {
				  $vals[] = '?';
				  $bind_values[] = $v;
			  }
		  }

		  $sql = "INSERT INTO `" . static::TBL . "` (" . implode(',', $cols) . ") VALUES (" . implode(',', $vals) . ")";
		  db::query($sql, $bind_values);

		  if (!$is_composite && !isset($this->_dirty[static::PK])) {
			  $this->_data[static::PK] = db::lastid();
		  }
		  $this->_new = false;

	  } else {
		  $sets = [];
		  $bind_values = [];

		  foreach ($this->_dirty as $k => $v) {
			  if ($v instanceof expr) {
				  $sets[] = "`$k` = " . $v->v;
			  } else {
				  $sets[] = "`$k` = ?";
				  $bind_values[] = $v;
			  }
		  }

		  $where = [];
		  if ($is_composite) {
			  foreach (static::PK as $k) {
				  $where[] = "`$k` = ?";
				  $bind_values[] = isset($this->_data[$k]) ? $this->_data[$k] : $this->_dirty[$k];
			  }
		  } else {
			  $where[] = "`" . static::PK . "` = ?";
			  $bind_values[] = isset($this->_data[static::PK]) ? $this->_data[static::PK] : $this->_dirty[static::PK];
		  }

		  db::query(
			  "UPDATE `" . static::TBL . "` SET " . implode(',', $sets) . " WHERE " . implode(' AND ', $where),
			  $bind_values
		  );
	  }

	  $this->_data  = array_merge($this->_data, $this->_dirty);
	  $this->_dirty = [];
	  
	  if (method_exists($this, 'save_after')) {
			$this->save_after();
		}
	
	  return $this;
  }
   function del(): bool {
		if ($this->_new) return false;

		$where = [];
		$bind  = [];

		if (is_array(static::PK)) {
			foreach (static::PK as $k) {
				$where[] = "`$k` = ?";
				$bind[]  = $this->_data[$k];
			}
		} else {
			$where[] = "`" . static::PK . "` = ?";
			$bind[]  = $this->_data[static::PK];
		}

		db::exec("DELETE FROM `" . static::TBL . "` WHERE " . implode(' AND ', $where), $bind);
		return true;
	}

	function reload() {
		if ($this->_new) throw new \Exception("ar_reload_unsaved");

		$fresh = is_array(static::PK) ? static::find($this->id()) : static::find($this->{static::PK});

		if (!$fresh) throw new \Exception("ar_not_found");
		$this->_data = $fresh->_data;
		$this->_dirty = [];
		return $this;
	}
	function isowned( ar $m ){
		return $this->{ "fk_".$m::TBL } == $m->id();
	}

    function mark_clean(): void {
        $this->_data  = array_merge($this->_data, $this->_dirty);
        $this->_dirty = [];
    }

    function offsetExists($k): bool  { return isset($this->_data[$k]); }
    function offsetGet($k)           { return isset($this->_data[$k]) ? $this->_data[$k] : null; }
    function offsetSet($k, $v): void { $this->_data[$k] = $v; }
    function offsetUnset($k): void   { unset($this->_data[$k]); }
    
    public function as_item() {
        return [
            'id'     => $this->id(),
            'title'  => $this->name ?? $this->titulo ?? 'ID: ' . $this->id(),
            'detail' => '',
            'icon'   => ''
        ];
    }
}
class arview extends ar{
	 static function sel()         {return new sel( static::seldrv() ); }
	 static function seldrv() {    }
}
class ar_buffer{
    private $pool = [];

    function add(ar $record): void {
        if ($record->clean()) return;
        $this->pool[get_class($record)][spl_object_id($record)] = $record;
    }

    function flush(): int {
        if (!$this->pool) return 0;
        $affected = 0;
        db::begin();
        try {
            foreach ($this->pool as $cls => $records)
                $affected += $this->upsert($cls, array_values($records));
            db::commit();
        } catch (\Throwable $e) {
            db::rollback();
            throw $e;
        }
        $this->pool = [];
        return $affected;
    }

    private function upsert(string $cls, array $records): int
    {
        $cols = [];
        foreach ($records as $r)
            foreach (array_keys($r->dirty()) as $k) $cols[$k] = true;
        if (!$cols) return 0;

        $cols     = array_keys($cols);
        $cols_sql = implode(',', array_map(function($c) { return "`$c`"; }, $cols));
        $row_ph   = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
        $rows_sql = implode(',', array_fill(0, count($records), $row_ph));
        
        $updates = implode(',', array_map(function($c) { return "`$c`=VALUES(`$c`)"; }, array_filter($cols, function($c) use ($cls) { return $c !== $cls::PK; })));

        $bind = [];
        foreach ($records as $r)
            foreach ($cols as $c) $bind[] = isset($r->$c) ? $r->$c : null;

        $sql = "INSERT INTO `".$cls::TBL."` ($cols_sql) VALUES $rows_sql"
             . ($updates ? " ON DUPLICATE KEY UPDATE $updates" : '');

        $affected = db::exec($sql, $bind);
        array_walk($records, function($r) { $r->mark_clean(); });
        return $affected;
    }
}




