<?php
namespace param {
    function desc(){ return [
        "title"=>"Database variables",
        "detail"=>"Your favorite switch provider"
    ]; }

    class provider extends \conf\provider_file {
        function __construct( private $model = \param\model\param::class ) { }
        static $loaded=false;        
        function get(string $k, bool $ex = false, mixed $def = null):mixed {
            if(!\db\db::ready())  goto normal;
            if(!static::$loaded){
                static::$loaded = [];
                foreach( ($this->model)::where("preload = 1")->fetch() as $r){
                    static::$loaded[$r->name] = $r->value;
                }
            }            
            if(isset( static::$loaded[$k] )) return static::$loaded[$k];            
            normal:
            return parent::get($k, $ex, $def);
        }
    }
}

namespace param\model {
    class param extends \db\ar { const TBL="param" ; }  
}

namespace param\form {
    use \param\model\param;
    class edit extends \form\modelform {
       const model= param::class,
       fields =[
           "name"=>["text",["req"=>1]],
           "value"=>["textarea"]
       ];
    }
    class view extends \form\modelform {
       const model= param::class,
       fields =[
            "name" => ["text"],
            "value" => ["textarea"],
       ],props = [
           "new" => ["param_form_edit"],
           "edit" => ["param_form_edit"]
       ];
    }
}
