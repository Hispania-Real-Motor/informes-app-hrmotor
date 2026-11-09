(() => {
    const definitions = {
        group: { id: 'octoberGroup', responseKey: 'groups' },
        medium: { id: 'octoberMedium', responseKey: 'media' },
        channel: { id: 'octoberChannel', responseKey: 'channels' },
        status: { id: 'octoberStatus', responseKey: 'statuses' },
    };

    const fill = (definition, values) => {
        const select = document.getElementById(definition.id);
        if (!select) return;

        const current = select.value;
        select.replaceChildren(new Option('Todos', ''));
        values.forEach((value) => select.add(new Option(value, value)));
        select.value = [...select.options].some((option) => option.value === current) ? current : '';
    };

    window.renderOctoberProvisionalFilterOptions = (filters) => {
        Object.values(definitions).forEach((definition) => fill(definition, filters[definition.responseKey] || []));
    };

    window.applyOctoberProvisionalFilters = (params) => {
        document.querySelectorAll('[data-october-filter]').forEach((select) => {
            if (select.value) params.set(select.dataset.octoberFilter, select.value);
        });
    };
})();
