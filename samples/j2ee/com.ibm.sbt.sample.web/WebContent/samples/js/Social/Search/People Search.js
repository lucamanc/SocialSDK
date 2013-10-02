require([ "sbt/connections/SearchService", "sbt/dom" ], 
	function(SearchService, dom) {
	
		var searchService = new SearchService();
	
		dom.byId("searchBtn").onclick = function(ev) {
		dom.byId("error").style.display = "none";
		dom.byId("peopleTable").style.display = "none";
		dom.byId("searching").innerHTML = "Searching...";
		
		var topic = dom.byId("topicInput").value;
		
		searchService.getPeople(topic).then(
			function(facets) {
				dom.byId("searching").innerHTML = "";
	            if (facets.length == 0) {
	            	showError("No people associated with topic: " + topic);
                } else {
                    for(var i=0; i<facets.length; i++){
                        var facet = facets[i];
                        createRow(facet);
                    }
                    dom.byId("peopleTable").style.display = "";
                }
			},
			function(error) {
				dom.byId("searching").innerHTML = "";
				showError(error.message);
			}
		);
	};
	
	var showError = function(message) {
		var errorDiv = dom.byId("error");
		errorDiv.style.display = "";
		errorDiv.innerHTML = message;
	};
	
	var createRow = function(facet) {
        var table = dom.byId("peopleTable");
        var tr = document.createElement("tr");
        table.appendChild(tr);
        var td = document.createElement("td");
        td.innerHTML = facet.getLabel();
        tr.appendChild(td);
        td = document.createElement("td");
        td.innerHTML = facet.getId();
        tr.appendChild(td);
    };
}
);
