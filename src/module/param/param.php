<?php
namespace param {
    function desc(){ return [
        "title"=>"Database variables",
        "detail"=>"Your favorite switch provider"
    ]; }

    //TODO: a preload/cache concept
    class provider extends \conf\provider_file {
        function __construct( private $model = \param\model\param::class ) { }
        function get(string $k, bool $ex = false, mixed $def = null):mixed {
            if(!\db\db::ready())  goto normal;
            $reg = ($this->model)::where("name = ?", $k)->fetch()->first();
            if(!$reg) goto normal;
            return $reg->value;
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
