(() => {
    const company = document.getElementById('org_company');
    const department = document.getElementById('org_department');
    if (!company || !department) return;

    const filterDepartments = () => {
        const companyId = company.value;
        let selectedIsVisible = department.value === '';
        [...department.options].forEach((option) => {
            if (option.value === '') return;
            const visible = option.dataset.company === companyId;
            option.hidden = !visible;
            option.disabled = !visible;
            if (visible && option.selected) selectedIsVisible = true;
        });
        if (!selectedIsVisible) department.value = '';
    };

    company.addEventListener('change', filterDepartments);
    filterDepartments();
})();

