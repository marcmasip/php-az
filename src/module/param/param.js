window.__AZ__.oninit.push(function(){
	Shortcuts.items.push({ 
        label: 'Settings', 
        icon: '⚙️', 
        action: () => new TableApp({ width:900, height:600, 
            title:"Settings", icon:"⚙️", schema:"param_form_view" }).launch()
    });
})

  

