<?php

namespace db\traits;


trait softdelete {
    
    
    //T.B.D 
    
    static function sel(){
        $s = parent::sel();
        $s->where("state!='deleted'");
        return $s;
    }
    
    
}