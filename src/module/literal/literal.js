
window.__AZ__.oninit.push(function(){
	Shortcuts.items.push({ 
        label: 'Literal', 
        public:true,
        icon: '🔠️', 
        action: () =>  new TableApp({width:900, height:600, title:"Literals",icon:"🔠️",schema:"literal_form_view"}).launch()
    });
})

  


