/** Show only the criteria relevant to the chosen service. */
document.addEventListener("DOMContentLoaded", function () {
    var form = document.querySelector(".nms-service-form");
    if (!form) return;
    var kind = form.elements.kind;
    var port = form.elements.port;
    var defaults = {http: "80", https: "443", dns: "53", tcp: "443"};
    var previous = kind.value;
    function update(change) {
        if (change && (!port.value || port.value === defaults[previous])) port.value = defaults[kind.value];
        form.querySelectorAll("[data-service-fields]").forEach(function (group) {
            var show = group.dataset.serviceFields === "web" ? ["http", "https"].includes(kind.value) : kind.value === "dns";
            group.hidden = !show;
            group.querySelectorAll("input,select").forEach(function (input) { input.disabled = !show; });
        });
        previous = kind.value;
    }
    kind.addEventListener("change", function () { update(true); });
    update(false);
});
