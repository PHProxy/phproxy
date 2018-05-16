"use strict";
document.addEventListener('DOMContentLoaded', function() {
	var x, l = document.getElementById("newWin");
	if (l && (x = l.querySelector("input"))) {
		x.addEventListener('click', function() {
			// get the form above
			for (var x = this.parentNode; x; x = x.parentNode) {
				if (x.method && x.localName.toLowerCase() === "form") {
					(this.checked) ? x.setAttribute("target", "_blank"): x.removeAttribute("target");
					break;
				}
			}
		}, false);
		l.style.removeProperty('display');
	}
}, false);
