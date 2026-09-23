<?php
/**
 * php-az - init.php
 * minimal, low-ceremony, macro-driven:
 * globals, autoload, conf, and basic io
 * 
 * @author Marc Masip Marín <marc@azestudio.net>
 */

namespace {
    
    // Here we go, a $MOD_MAP is already defined
    if(defined("AZ_VER")) return; define('AZ_VER', '0.4.0');
    if(!defined("AZ_TEST")) define('AZ_TEST', false );
    if(!isset($MOD_MAP))  $MOD_MAP = [__DIR__ . '/module' => $WEB_BASE . '_az/module'];
    if(!isset($WEB_BASE)) $WEB_BASE = "/";

    // exceptions with additional debug data
    class err_ex extends Exception {
        public readonly string $id;
        function __construct(string $msg, public readonly mixed $data = null) {
            $this->id = explode(' ', $msg, 2)[0]; parent::__construct($msg, 0);
        }
    }
    
    // conf provider may be extended eg. with database see param module
    function err(string $msg, mixed $data=null): never { throw new err_ex($msg, $data); }
    function conf(string $k, bool $ex=false, mixed $def=null): mixed {
        \conf\state::$provider ??= new \conf\provider_file();
        return \conf\state::$loaded[$k] ??= \conf\state::$provider->get($k, $ex, $def);
    }
    function conf_url($path,$args = null){
        return conf("url_protocol").conf("url_host").$path. ($args? "?".http_build_query($args) : "");
    }

    \io\in::$default = php_sapi_name() === 'cli' ? \io\in::cli() : \io\in::web();

    // global helpers
    function in_any  (string $k, bool $ex=false, mixed $def=null): mixed   { return \io\in::$default->any($k, $ex, $def); }
    function in_str  (string $k, bool $ex=true,  mixed $def=null): ?string { return \io\in::$default->str($k, $ex, $def); }
    function in_int  (string $k, bool $ex=true,  mixed $def=null): ?int    { return \io\in::$default->int($k, $ex, $def); }
    function in_num  (string $k, bool $ex=true,  mixed $def=null): ?float  { return \io\in::$default->num($k, $ex, $def); }
    function in_bool (string $k, bool $ex=false, mixed $def=null): ?bool   { return \io\in::$default->bool($k, $ex, $def); }
    function in_arr  (string $k, bool $ex=true,  mixed $def=null): ?array  { return \io\in::$default->arr($k, $ex, $def); }
    function in_json (string $k, bool $ex=true,  mixed $def=null): mixed   { return \io\in::$default->json($k, $ex, $def); }
    function in_expr (string $k, string $p, bool $ex=true, mixed $def=null): ?string { return \io\in::$default->expr($k, $p, $ex, $def); }

    function out_json (mixed $data, int $flags = JSON_PRETTY_PRINT): never { \io\out::json($data, $flags); }
    function out_ok   (mixed $data = null): never { \io\out::ok($data); }
    function out_fail (string $code="1", mixed $info=null, int $status=400): never { \io\out::fail($code, $info, $status); }
    function out_html (string $html): never { \io\out::html($html); }
    function out_redir(string $dst): never  { \io\out::redir($dst); }
    function out_exit (): never { \io\out::exit(); }
 
   /**
    * Resolves module\group\object namespaces with file fallbacks:
    * mod/group/name.php -> mod/group.php -> mod/mod.php
    * $MOD_MAP provides base lookup paths (local_path => url_path).
    */
    spl_autoload_register(function (string $cls) {
        global $MOD_MAP;
        $p = explode('\\', trim($cls, '\\'));
        if (count($p) < 2) return;

        $mod  = array_shift($p);
        $name = array_pop($p);
        $sub  = implode('/', $p);

        foreach ($MOD_MAP as $d=>$route) {
            if (!is_dir($base = "$d/$mod")) continue;
            $paths = $sub 
                ? ["$base/$sub/$name.php", "$base/$sub.php", "$base/$mod.php"]
                : ["$base/$name.php", "$base/$mod.php"];

            foreach ($paths as $f) {
                if (is_file($f)) {
                   
                    require_once $f;
                    if (class_exists($cls, false) || interface_exists($cls, false) || trait_exists($cls, false)) {
                        return; 
                    }
                }
            }
        }
    });
    function mod_dir(string $mod): string {
        global $RUN,$MOD_MAP;
        foreach ($MOD_MAP as $d) {
            if (is_dir($RUN."/public/"."$d/$mod")) return $RUN."/public/$d/$mod/";
        }
        err("mod_dir_err: $mod");
    }
     
    function mod_url(string $mod, string $file = ''): string {
        global $MOD_MAP;
        foreach ($MOD_MAP as $dir => $url) {
            if (is_dir("$dir/$mod")) return "$url/$mod/" . ltrim($file, '/');
        }
        err("mod_url_err: $mod");
    }

    // util convention for deploying module assets
    function out_assets(array $active_modules,$types=["css","js"]): void {
        global $MOD_MAP;
        $html = '';
        foreach ($active_modules as $mod) {
            foreach ($MOD_MAP as $dir => $url) {
                if (is_dir("$dir/$mod")) {
                    $base_f = "$dir/$mod/$mod";
                    $base_u = "$url/$mod/$mod";

                    foreach($types as $type){
                        if($type=="css"){
                             if (is_file("$base_f.css")) $html .= "<link rel='stylesheet' href='$base_u.css'>\n";
                        }else{
                             if (is_file("$base_f.js"))  $html .= "<script src='$base_u.js'></script>\n";
                        }
                    }
                    break;
                }
            }
        }
        echo $html;
    }
    function out_slot(string $name, ?callable $render = null): void {
        static $slots = [];
        if ($render) {
            ob_start(); $render(); $slots[$name] = ob_get_clean();
        } else {
            echo $slots[$name] ?? '';
        }
    }

    
    // a basic router 
    function mod_action($arg="mod",$folder="action"){
        if (empty($mod_action = in_str($arg, false))) return;
        [$mod, $action] = explode(":", $mod_action) + [null, null];
        if (!preg_match('/^[a-z0-9_-]+$/i', $mod) || !preg_match('/^[a-z0-9_-]+$/i', $action)) {
            err("invalid_module_action");
        }
        
        $file = mod_dir($mod) . "$folder/$action.php";

        if (!is_file($file)) out_fail("action_not_found");

        try {
            \log\debug("Action ". realpath($file));
            return include $file;
        } catch (\Exception $e) {
            \log\error("action_exception".$e->getMessage(), $e->getTraceAsString());
            out_fail($e->getCode(), $e->getMessage());
        }    
    }
   
}

namespace conf {
    interface provider { function get(string $k, bool $ex=false, mixed $def=null): mixed; }
    class provider_file implements provider {
        function get(string $k, bool $ex=false, mixed $def=null): mixed {
            global $RUN; static $cfg = null;
            if ($cfg === null) {
                $cfg = is_file($f = "$RUN/config.php") ? require $f : [];
                if (is_file($f = "$RUN/config.env.php")) $cfg = array_merge($cfg, require $f);
            }
            return $k === null ? $cfg : ($cfg[$k] ?? ($ex ? \err("conf_req: $k") : $def));
        }
    }
    class state {
        static array $loaded = [];
        static ?provider $provider = null;
    }
}

namespace io {
    class in {
        static self $default;
        static function web(): self { return new self($_GET + $_POST); }
        static function web_body(): self { 
           $raw = file_get_contents('php://input');
            $json = $raw ? (json_decode($raw, true) ?: []) : [];
            return new self(array_merge($_GET, $_POST, $json));
        }
        static function cli(): self {
            global $argv; parse_str(implode('&', array_slice($argv ?? [], 1)), $d);
            return new self($d);
        }

        public function __construct(public readonly array $data = []) {}
        function raw(): array { return $this->data; }
        function has(string $k): bool { return array_key_exists($k, $this->data); }
        function any(string $k, bool $ex=false, mixed $def=null): mixed {
            return array_key_exists($k, $this->data) ? $this->data[$k] : ($ex ? \err("in_req: $k") : $def);
        }
        function sub(string $k, bool $ex=true): self { return new self($this->arr($k,$ex) ?? []); }
        
// Typed extractors
        function str(string $k, bool $ex=true, mixed $def=null): ?string { $v = $this->any($k,$ex,$def); return $v !== null ? (string)$v : null; }
        function int(string $k, bool $ex=true, mixed $def=null): ?int    { $v = $this->any($k,$ex,$def); return $v !== null ? (int)$v : null; }
        function num(string $k, bool $ex=true, mixed $def=null): ?float  { $v = $this->any($k,$ex,$def); return $v !== null ? (float)$v : null; }
        function arr(string $k, bool $ex=true, mixed $def=null): ?array  { 
            $v = $this->any($k,$ex,$def); return ($v !== null && !is_array($v)) ? ($ex ? \err("in_arr: $k") : null) : $v; 
        }
        function bool(string $k, bool $ex=false, mixed $def=null): ?bool { 
            $v = $this->any($k,$ex,$def); 
            return $v !== null ? filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool)$v : null; 
        }

// Validated extractors
        function arrin(string $k, bool $ex=true): array {
            return array_map(fn($r) => is_array($r) ? new self($r) : \err("in_arrin: $k"), $this->arr($k,$ex) ?? []);
        }
        function json(string $k, bool $ex=true, mixed $def=null): mixed { $v = $this->str($k,$ex); return $v !== null ? val::json($v,$ex,$def) : $def; }
        function email(string $k, bool $ex=true, mixed $def=null): ?string { $v = $this->str($k,$ex); return $v !== null ? (val::email($v) ?? ($ex ? \err("in_email: $k") : $def)) : $def; }
        function expr(string $k, string $p, bool $ex=true, mixed $def=null): ?string { $v = $this->str($k,$ex); return $v !== null ? (val::expr($v,$p) ?? ($ex ? \err("in_expr: $k") : $def)) : $def; }
    }

    class val {
        static function resolve(mixed $v): mixed { return is_object($v) && method_exists($v,'raw') ? $v->raw() : (is_array($v) ? array_map(self::resolve(...), $v) : $v); }
        static function expr(string $v, string $pat): ?string { return preg_match($pat, $v) ? $v : null; }
        static function email(string $v): ?string { return filter_var($v, FILTER_VALIDATE_EMAIL) ?: null; }
        static function url(string $v): ?string { return filter_var($v, FILTER_VALIDATE_URL) ?: null; }
        static function ip(string $v): ?string { return filter_var($v, FILTER_VALIDATE_IP) ?: null; }
        static function in(mixed $v, array $a): mixed { return in_array($v, $a, true) ? $v : null; }
        static function json(string $s, bool $ex=true, mixed $def=null): mixed { $r = json_decode($s, true); return $r ?? ($ex ? \err("val_json: fail") : $def); }
        static function range(int|float $v, int|float $min=null, int|float $max=null): int|float|null { return ($min === null || $v >= $min) && ($max === null || $v <= $max) ? $v : null; }
        static function cleanpath(string $p): string {
            $p = str_replace(['\\', chr(0)], ['/', ''], $p);
            $parts = array_filter(explode('/', $p), fn($v) => strlen($v) > 0 && $v !== '.' && $v !== '..'); 
            return implode('/', $parts);
        }
       
    }

    class out {
        static $enc="utf-8";
        static function exit(): never { if (\AZ_TEST) err('AZ_EXIT'); exit; }
        static function json(mixed $data, int $flags = JSON_PRETTY_PRINT): never { 
            header('Content-Type: application/json; charset='.static::$enc); echo json_encode(val::resolve($data), $flags); self::exit(); 
        }
        static function ok(mixed $data = null): never { self::json(['ok' => true, 'data' => $data]); }
        static function fail(string $code="1", mixed $info=null, int $status=400): never { 
            http_response_code($status); self::json(['ok' => false, 'error' => $code, 'info' => $info]); 
        }
        static function html(string $html): never { header('Content-Type: text/html; charset='.static::$enc); echo $html; self::exit(); }
        static function redir(string $dst): never { header("Location: $dst"); self::exit(); }
    }
}


