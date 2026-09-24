<?php
/**
 * php-az-form - form.php
 * minimal, low-ceremony, macro-driven:
 * The Only Forms pattern project manages Fields and Processes. 
 * Centralizes app API definition, ACL, and CSRF operations.
 * W.I.P.
 * 
 * @author Marc Masip Marín + / marc at azestudio.net
 */
namespace form {
    
    use \auth\acl;
     
    function desc() { 
        return [
            "title" => "Only-Forms Pattern", 
            "desc"  => "Provides CRUD and listing fuctions",
            "ver"   => "0.6.0"
        ];
    }

    /**
     * Schema decribes query columns or form fields
     * Can be search by name as <module>_form_<name>
     * 
     * .fields = ["name"=> [ "text", [ "req"=>1 ] ] ;
     * .props = is for util metadata about form logics
     * .model = optional pointing to active record table
     * .acl = optional map for defining actions for each role
     * 
     */
    abstract class schema {

        const 
        NAME = "generic_schema", model = null, fields =  [], 
        acl = [
            acl::ROLE_ANY => [ acl::ACTION_VIEW, acl::ACTION_EDIT, acl::ACTION_LIST ]
        ], 
        props = [];

        /**
         * Form identification as module, group ("form") and name
         * @param type $id 
         * @param type $ex
         * @return \form\c
         */
        static function named($id, $ex = true) {
            $p = explode('_', $id, 3);
            if (count($p) !== 3 || $p[1] !== 'form') {
                return $ex ? \err("invalid_schema_name") : null;
            }

            [$mod, $type, $name] = $p;
            $cls = "\\$mod\\$type\\$name"; 

            if (!class_exists($cls) || !is_subclass_of($cls, self::class)) {
                return $ex ? \err("invalid_schema_type $cls") : null;
            }
            return new $cls();
        }
        
        // Schema definitions via constant or override
        function fields() { return static::fields; }
        function props()  { return static::props; }
        function acl()    { return static::acl; }
        function model()  { return static::model; }
        
        // Hook to complement base record selection
        function select($s) { }

        function sel() {
            $m = $this->model();   
            $s = $m::sel();
            $this->select($s);
            return $s;
        }

        // Cached schema fields
        public function fields_info() { 
            static $infos = []; 
            $cls = static::class; 
            return $infos[$cls] ??= $this->fields();
        }
        
        // Schema fields to the SQL
        function sel_fields($s) {
            foreach ($this->fields_info() as $k => $inf) {
                $this->sel_field($s, $k, $inf[0], $inf[1] ?? []);
            }  
        }
        function sel_field($s, $name, $type, $options = []) {
            $s->col($name);
        }

        // Export field definitions
        function schema($result = null) {         
            $r = [];
            foreach ($this->fields_info() as $name => $inf) {   
                $opts = $inf[1] ?? [];
                $r[$name] = [$inf[0], $this->info_fields_options($result, $name, $inf[0], $opts)];
            } 
            return $r;
        }
        
        // Additional data for field def.
        public function info_fields_options($result, $name, $type, $o) {
            if (isset($o["schema"], $o["fetch"])) {
                $o["items"] = self::named($o["schema"])->sel()->fetch()->as_items();
            }           
            return $o;
        }
        
        // Extract the schema fields form a recordset
        function export($r) {
            if (!($r instanceof \db\ar)) {
                return $r;
            }
            
            $inf = $this->fields_info();
            $a = [$r::PK => $r->id()];
            
            foreach ($inf as $name => $field_info) {    
                $this->export_field($r, $name, $field_info, $a);
            }
            return $a;
        }

        // Hook to modify or map a specific field during export
        function export_field($r, $name, $inf, &$t) {
           if (isset($r->{$name})) {
               $t[$name] = $r->{$name};
           }
        }
        
        // Hook to append related information to a single record export
        function export_extra($r, &$extra) {}
      
        // Exports a collection of records
        function export_list($r) {
            if (!($r instanceof \db\rows)) \err("cant_export");
            
            $b = [];
            foreach ($r as $rr) {
                $b[] = $this->export($rr);
            }
            return $b;
        }
        
        // Hook to append shared related information for a record list
        function export_list_extra($r, &$extra) {}

    }

    /**
     * Uses the schema for describe the expected fields
     */
    abstract class form extends schema {

        protected array $data = [];
        protected array $errors = [];

        public function __construct() {
            // if (session_status() === PHP_SESSION_NONE) session_start();
        }

        public function setup() {}

        public static function get_csrf_token(): string {
            $tokenKey = static::NAME . '_csrf_token';
            if (empty($_SESSION[$tokenKey])) {
                $_SESSION[$tokenKey] = bin2hex(random_bytes(32));
            }
            return $_SESSION[$tokenKey];
        }

        public function view($in) {}

        public function is_post(): bool {
            return $_SERVER['REQUEST_METHOD'] === 'POST';
        }

        public function recv(\io\in $in = null): bool {
            if($in==null)$in =  \io\in::$default;
            $clean_data = [];
            $this->errors = [];

            foreach (static::fields_info() as $name => $inf) {
                $type = $inf[0]; // ej: 'str', 'int', 'email', 'bool'
                $opt = $inf[1] ?? [];
                $required = $opt['required'] ?? false;
                $regexp = $opt['regexp'] ?? null;

                if ($in->has($name)) {
                    try {
                        if ($regexp) {
                            $valor = $in->expr($name, $regexp, true);
                        } elseif (method_exists($in, $type)) {
                            $valor = $in->$type($name, true); 
                        } else {
                            $valor = $in->any($name);
                        }
                        $clean_data[$name] = $valor;
                    } catch (\Exception $e) {
                        $this->errors[$name] = $e->getMessage();
                    }
                } elseif ($required) {
                    $this->errors[$name] = "required_field";
                }
            }

            $this->valid = new \io\in($clean_data);

            return empty($this->errors);
        }

        public function get_errors(): array { 
            return $this->errors; 
        }

        public function proc() {
            $this->setup();
        }
    }

    /**
     * A form/process that is stores in a reacord
     */
    abstract class modelform extends form {

        public $record;
        public $user;
        public array $changes = [];

        public function proc_auth() {
            $this->user = \auth\user();

            $model_class = static::model;
            if (!$model_class) throw new \Exception("model_not_defined_for_form");

            $id = $this->valid->int('id', false) ?? \in_int('id', false);

            if ($id) {
                $this->record = $model_class::find($id);
                if (!$this->record) throw new \Exception("invalid_record $id");
            } else {
                $this->record = new $model_class();
            }

            if (method_exists($this->record, "aclr")) {
                $this->record->aclr($this->user, \auth\acl::ACTION_EDIT);
            }
        }

        public function proc() {
            $this->setup(); 
            $this->proc_auth();

            $this->to($this->record, $this->valid, $this->changes); //copy vals
            
            $this->model_edit($this->record);                       //edit hook
            $this->record->save();
            $this->model_saved($this->record);                      //saved hook

            if (!empty($this->changes)) {
                $model_class = static::model;
                \log\trace("form:save model={$model_class} uid={$this->user->id()} id={$this->record->id()} data=" . json_encode($this->changes));
            }

            return $this->export($this->record);
        }

        protected function model_edit($m) {}
        protected function model_saved($m) {}

        // To Record copy form vals to the active record
        protected function to($record, \io\in $valid_data, array &$changed) {
            foreach (static::fields_info() as $name => $inf) { 
                if (($inf[1]['map'] ?? true) === false) {
                    continue;
                }
                $this->tof($name, $valid_data, $record, $changed);
            }
        }
        //To Record Field can be used as a hook for special copy (eg: hash a passwd)
        protected function tof(string $k, \io\in $valid_data, $record, array &$changed) {
            if (!$valid_data->has($k) || $valid_data->any($k) === "") return;

            $new_val = $valid_data->any($k);

            if ($record->$k !== $new_val) {
                $changed[$k] = $new_val;
                $record->$k = $new_val; 
            }
        }

        //asign the inputs for the form
        public function recv(\io\in $in=null): bool {
            if($in==null) $in = \io\in::$default;
            $success = parent::recv($in);
            if ($in->has("id") && !$this->valid->has("id")) {
                $raw = $this->valid->raw();
                $raw['id'] = $in->int("id", false);
                $this->valid = new \io\inarr($raw);
            }
            return $success;
        }

    }

    // util base to delete records
    abstract class modelformdel extends modelform {

        public function proc() {
            $this->setup();
            $this->proc_auth();
            $model_class = static::model; 
            \log\trace("form:save model={$model_class} uid={$this->user->id()} id={$this->record->id()} delete");
            $this->record->del();
            return true;
        }
    }

}

namespace {
    function in_form(string $k, bool $ex = true): ?\form\schema {
       if (!($id = in_str($k, $ex))) return null;
       return \form\schema::named($id);
    }
}