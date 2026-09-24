<?php
/**
 * php-az-literal - literal.php
 * minimal, low-ceremony, macro-driven:
 * user messages table
 * 
 * @author Marc Masip Marín + / marc at azestudio.net
 */
namespace literal {
    function desc(){ return [
        "title"=>"Database texts",
        "detail"=>"Your favorite text provider"
    ]; }
    
    class state {
        static $locale="en";
    }
}
namespace {
    function lang($k){
        return \literal\model\words::get($k);
    }
}
namespace literal\model {
    //TODO: by apt. and locale, message variables
    class words extends \db\ar {
       const TBL="literal" ;
       static $loaded = [];
       static function get($k=null){
           if(!static::$loaded){
               static::$loaded = words::sel()->fetch()->map("name");
           }
           if(!isset(static::$loaded[$k])){
               return $k;
           }
           return static::$loaded[$k]->value;
       }
    }  
}
namespace literal\form {
    class edit extends  \param\form\edit{
       const model= \literal\model\words::class;
    }   
    class view extends \param\form\view {
       const 
        model= \literal\model\words::class,
        props = [
           "new" => ["literal_form_edit"],
           "edit" => ["literal_form_edit"]
       ];
    }
}