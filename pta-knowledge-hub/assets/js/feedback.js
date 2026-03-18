/**
 * PTA Knowledge Hub — Feedback ("Was This Helpful?")
 *
 * Sends thumbs-up/down votes via AJAX. Replaces buttons with
 * a thank-you message and updated counts on success.
 *
 * Uses safe DOM methods only (createElement, textContent).
 */

(function () {
    "use strict";

    var container = document.getElementById("ptk-feedback");
    if (!container) return;

    var buttons = container.querySelectorAll(".ptk-feedback-btn");
    if (!buttons.length) return;

    buttons.forEach(function (btn) {
        btn.addEventListener("click", function () {
            var postId  = btn.getAttribute("data-post-id");
            var helpful = btn.getAttribute("data-helpful");

            // Disable all buttons during request.
            buttons.forEach(function (b) { b.disabled = true; });

            var body = new FormData();
            body.append("action", "pta_feedback_vote");
            body.append("_wpnonce", ptkFeedback.nonce);
            body.append("post_id", postId);
            body.append("helpful", helpful);

            fetch(ptkFeedback.ajaxUrl, { method: "POST", body: body })
                .then(function (res) { return res.json(); })
                .then(function (json) {
                    if (json.success) {
                        showThanks(json.data.counts);
                    } else if (json.data && json.data.message === "already_voted") {
                        showAlreadyVoted(json.data.counts);
                    } else {
                        showError();
                    }
                })
                .catch(function () {
                    showError();
                });
        });
    });

    function clearContainer() {
        while (container.firstChild) {
            container.removeChild(container.firstChild);
        }
    }

    function showThanks(counts) {
        clearContainer();
        var thanks = document.createElement("p");
        thanks.className = "ptk-feedback-thanks";
        thanks.textContent = "Thanks for your feedback!";
        container.appendChild(thanks);
        showCounts(counts);
    }

    function showAlreadyVoted(counts) {
        clearContainer();
        var msg = document.createElement("p");
        msg.className = "ptk-feedback-thanks";
        msg.textContent = "You\u2019ve already shared your feedback.";
        container.appendChild(msg);
        showCounts(counts);
    }

    function showCounts(counts) {
        if (!counts) return;
        var p = document.createElement("p");
        p.className = "ptk-feedback-counts";
        p.textContent = counts.helpful + " found this helpful \u00b7 " + counts.not_helpful + " did not";
        container.appendChild(p);
    }

    function showError() {
        buttons.forEach(function (b) { b.disabled = false; });
        var msg = document.createElement("p");
        msg.className = "ptk-feedback-counts";
        msg.style.color = "#dc2626";
        msg.textContent = "Something went wrong. Please try again.";
        container.appendChild(msg);
    }

})();
