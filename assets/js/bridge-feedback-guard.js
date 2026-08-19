(() => {
    'use strict';

    const EARLY_ERROR_TEXT = 'A extensão não confirmou o recebimento do comando.';
    const WAITING_TEXT = 'Aguardando a extensão buscar o comando...';
    const GRACE_PERIOD_MS = 45000;

    let lastSubmitAt = 0;

    function markSubmitted() {
        lastSubmitAt = Date.now();
    }

    function withinGracePeriod() {
        return lastSubmitAt > 0 && Date.now() - lastSubmitAt < GRACE_PERIOD_MS;
    }

    function softenPrematureError(feedback) {
        if (!feedback || feedback.textContent.trim() !== EARLY_ERROR_TEXT || !withinGracePeriod()) {
            return;
        }
        feedback.textContent = WAITING_TEXT;
        feedback.classList.remove('is-error');
    }

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('open-art-form');
        const feedback = document.getElementById('open-art-feedback');
        if (!form || !feedback) return;

        form.addEventListener('submit', markSubmitted, true);
        new MutationObserver(() => softenPrematureError(feedback)).observe(feedback, {
            childList: true,
            characterData: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class'],
        });
    });
})();
