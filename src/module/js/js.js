/**
 * php-az-js - js.js
 * minimal, low-ceremony, macro-driven:
 * dinamic document contents with control logics.
 * 
 * @author Marc Masip Marín <marc@azestudio.net>
 */
const mk = (tag, content, attrs = {}) => {
    const el = typeof tag === 'function' ? new tag() : document.createElement(tag);
    for (const key in attrs) {
        if (key.startsWith('on') && typeof attrs[key] === 'function') {
            el[key.toLowerCase()] = attrs[key];
        } else if (key === 'props' && typeof el.setProps === 'function') {
            el.setProps(attrs[key]);
        } else {
            el.setAttribute(key, attrs[key]);
        }
    }
    if (content !== undefined) _fill(el, content);
    return el;
};
//most used for generic containers
const mkv  = (cls, content, attrs = {}) => mk('div', content, { ...attrs, class: cls });
const mkbt = (label, onclick, attrs = {}) => mk('button', label, { ...attrs, type: 'button', onclick });

/*
 *  items are generic descriptor for:
 * .title .detail .icon .image 
 * .acc Right accessories
 * .lacc Left accessories
 * .action function to triger on click o related action
 */
const mkit = (obj,attr) => {
    if(!obj)obj={}
    
    if(obj.action){
        if(!attr)attr={}
        attr.onclick = obj.action;
    }
    
    const v = mkv("row "+(obj.cls||""),null,attr);
    
    if( obj.lacc ) v.append( mkv("row-lacc", obj.lacc ) );
    
	if( obj.image ){
        v.append( mkv("row-icon", mk( "img", [], {src:obj.image} ) ));
	}else{
		if(obj.icon)v.append( mkv("row-icon",obj.icon) );
	}
    
     var desc = obj.detail || null;
    if(desc){
        desc =  mkv("row-detail",obj.detail || obj.desc || obj.description ) 
    }

    v.append(  mkv("row-text",[
		mkv("row-title",obj.title || obj.name || obj.nombre ) , desc
	]) )

    if(obj.acc){//accessories
        v.append( mkv("row-acc", obj.acc ) );
    }
    
    if(obj.extra){
        _fill(v, obj.extra )
    }
    
    return v;
}      
        
// the recursive array provides versatility composing contents, eg: 
// mkv("demo", [ this.cnt = mkv("count",1), [ [ctl,view], "a" ], "b", 1 ] );
const _fill = (parent, content,reset) => {
    if(reset===true) parent.innerHTML ="";
    if (Array.isArray(content)) content.forEach(c => _fill(parent, c));
    else if (content instanceof Ctl) {
        if(!content.view)content.init()
        parent.appendChild(content.view);
    } else if (content?.view instanceof HTMLElement) parent.appendChild(content.view);
    else if (content instanceof Node) parent.appendChild(content);
    else if (content != null) parent.append(String(content));
};

/**
 * Base controller — keep a .view:Element, call init() to build it.
 * Subclasses define tpl() returning the root element.
 * conf(key)        → pref > config[key]
 * pref(key, val?)  → get/set localStorage preference
 */
class Ctl {
    
    constructor(config) { this.config = config || {}; }

    init() {
        if (!this.view) {
			if( typeof this.tpl === 'function' ){
				this.view = this.tpl()
			}else if( this.tpl instanceof Element ){
				this.view =  this.tpl.cloneNode(true)
			}else{
				this.view = null;
			}
		}
        return this.view;
    }

    conf(key) { return this.pref(key) ?? this.config?.[key]; }

    pref(key, val = null) {
        const k = 'az_'+(__AZ__ ? (__AZ__.localpref || "pref") : "pref" )+'_' + key;
        if (val !== null) { localStorage.setItem(k, JSON.stringify(val)); return val; }
        const s = localStorage.getItem(k);
        return s !== null ? JSON.parse(s) : null;
    }

    error(e){ console.log("CTL ERROR ",e)  }
	warn(e){ console.log("CTL WARNING ",e)  }
}


// utils test
const net_ct_form = "application/x-www-form-urlencoded";
const net_ct_json = "application/json";
async function net(url, args = {}, method = 'GET', ct = 'form', ctr = "json") {
    const opts = { method, headers: {} };
    
    if (method === 'GET') {
        const q = new URLSearchParams(args).toString();
        if( url.indexOf("?")===-1){
            if (q) url += '?' + q;
        }else{
            if (q) url += '&' + q;
        }
    } else if (args instanceof FormData) {
        opts.body = args;
    } else if (ct === 'application/json') {
        opts.headers['Content-Type'] = 'application/json';
        opts.body = JSON.stringify(args);
    } else {
        opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        opts.body = new URLSearchParams(args).toString();
    }
    
    const r = await fetch(url, opts);
    
    if (!r.ok) {
        var eid = `http_${r.status}`;
        try{
            var obj = await r.json();
            if(obj.info){
                eid = obj.info;
            }
        }catch(e){}
        
        throw new Error(eid);
    }
    if(ctr == "json") return r.json();
    return r.text();
}
const _dict = {};
const lang = new Proxy(_dict, {
    get: (target, prop) => target[prop] ?? prop
});
const loadLang = (...objects) => Object.assign(_dict, ...objects);




