/**
 * Controls the modal that fires when clicking "export" from the CMS actions bar
 *
 */
(function () {
    if (window.__recordPackerModalReady) { return; }
    window.__recordPackerModalReady = true;

    function closeModal(modalEl) {
        if (!modalEl) { return; }
        modalEl.classList.remove('show');
        modalEl.style.display = 'none';
        modalEl.remove();
        document.body.classList.remove('modal-open');
        document.querySelectorAll('[data-record-packer-backdrop]').forEach(function (el) {
            el.remove();
        });
    }

    document.addEventListener('click', function (e) {
        var trigger = e.target.closest('[data-toggle="modal"][data-modal]');

        if (trigger) {
            e.preventDefault();
            e.stopPropagation();

            var existing = document.querySelector(trigger.getAttribute('data-target'));
            if (existing) { closeModal(existing); }

            var wrapper = document.createElement('div');
            wrapper.innerHTML = trigger.getAttribute('data-modal');
            var modalEl = wrapper.firstElementChild;
            document.body.appendChild(modalEl);

            var backdrop = document.createElement('div');
            backdrop.className = 'modal-backdrop fade show';
            backdrop.setAttribute('data-record-packer-backdrop', '1');
            document.body.appendChild(backdrop);

            modalEl.classList.add('show');
            modalEl.style.display = 'block';
            document.body.classList.add('modal-open');

            return;
        }

        var dismiss = e.target.closest('[data-dismiss="modal"]');

        if (dismiss) {
            e.preventDefault();
            closeModal(dismiss.closest('.modal'));

            return;
        }

        if (e.target.classList && e.target.classList.contains('modal') && e.target.classList.contains('show')) {
            closeModal(e.target);
        }
    });
})();