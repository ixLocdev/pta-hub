/**
 * PTA Knowledge Hub — Copy-to-Clipboard
 *
 * Handles the "Copy Answer" button on FAQ cards and the
 * single entry view. Uses the Clipboard API with a fallback.
 */

(function () {
    "use strict";

    // Use event delegation so dynamically rendered cards work too.
    document.addEventListener("click", function (e) {
        var btn = e.target.closest("[data-copy-text]");
        if (!btn) return;

        e.preventDefault();
        e.stopPropagation();

        var text = btn.getAttribute("data-copy-text");
        if (!text) return;

        copyToClipboard(text).then(function () {
            showCopiedFeedback(btn);
        });
    });

    /**
     * Copy text to clipboard with fallback for older browsers.
     */
    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }

        // Fallback: textarea method
        return new Promise(function (resolve) {
            var textarea = document.createElement("textarea");
            textarea.value = text;
            textarea.style.position = "fixed";
            textarea.style.left = "-9999px";
            document.body.appendChild(textarea);
            textarea.select();
            try {
                document.execCommand("copy");
            } catch (e) {
                // Silently fail
            }
            document.body.removeChild(textarea);
            resolve();
        });
    }

    /**
     * Show visual feedback: swap label to "Copied!" briefly.
     */
    function showCopiedFeedback(btn) {
        var label = btn.querySelector(".ptk-copy-label");
        var originalText = label ? label.textContent : "";
        var originalAriaLabel = btn.getAttribute("aria-label") || "";

        btn.classList.add("ptk-copied");
        btn.setAttribute("aria-label", "Copied to clipboard");
        if (label) label.textContent = "Copied!";

        setTimeout(function () {
            btn.classList.remove("ptk-copied");
            btn.setAttribute("aria-label", originalAriaLabel);
            if (label) label.textContent = originalText;
        }, 1500);
    }

})();
