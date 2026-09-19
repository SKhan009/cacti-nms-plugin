/** Filter device-reading evidence without requesting or modifying device data. */
document.addEventListener("DOMContentLoaded", function () {
	var rows=document.querySelectorAll("[data-reading-row]"), tabs=document.querySelectorAll("[data-reading-tab]"), view=document.querySelector("[data-reading-view]"), protocol=document.querySelector("[data-reading-protocol]");
	function apply(category){rows.forEach(function(row){var categoryMatch=category==="all"||row.dataset.category===category||(category==="problems"&&row.dataset.problem==="1");var protocolMatch=!protocol||protocol.value==="all"||row.dataset.protocol===protocol.value;row.hidden=!(categoryMatch&&protocolMatch);});tabs.forEach(function(tab){tab.classList.toggle("active",tab.dataset.readingTab===category);});if(view)view.value=category;}
	tabs.forEach(function(tab){tab.addEventListener("click",function(){apply(tab.dataset.readingTab);});});if(view)view.addEventListener("change",function(){apply(view.value);});if(protocol)protocol.addEventListener("change",function(){apply(view?view.value:"all");});apply(view?view.value:"all");
});
