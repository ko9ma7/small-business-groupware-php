(() => {
    const modeInputs = [...document.querySelectorAll('input[name="input_mode"]')];
    const periodFields = document.querySelector('.ex-period-fields');
    const form = document.getElementById('quickReportForm');
    const submitButton = form?.querySelector('button[type="submit"]');

    const updateMode = () => {
        const selected = modeInputs.find((input) => input.checked);
        if (selected && periodFields) periodFields.dataset.mode = selected.value;
    };

    modeInputs.forEach((input) => input.addEventListener('change', updateMode));
    updateMode();

    form?.addEventListener('submit', () => {
        if (!submitButton) return;
        submitButton.disabled = true;
        submitButton.textContent = '저장 중...';
    });
})();

