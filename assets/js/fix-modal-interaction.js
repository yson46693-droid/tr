/**
 * إصلاح مشكلة عدم القدرة على التفاعل مع Modal
 * Fix Modal Interaction Issue
 */

(function () {
    'use strict';

    function enableModal(modal) {
        if (!modal || !modal.style) {
            return;
        }

        try {
            modal.style.pointerEvents = 'auto';
            const focusable = modal.querySelector('input, select, textarea, button');
            if (focusable) {
                focusable.focus({ preventScroll: true });
            }
        } catch (error) {
            console.warn('Error enabling modal:', error);
        }
    }

    document.addEventListener('show.bs.modal', function (event) {
        enableModal(event.target);
    });

    document.addEventListener('shown.bs.modal', function (event) {
        enableModal(event.target);
    });
})();

