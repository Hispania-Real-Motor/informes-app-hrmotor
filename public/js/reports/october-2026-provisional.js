function normalizedSearchValue(value) {
    return String(value || '').trim().toLocaleLowerCase('es');
}

/**
 * Restricts the provisional page to one independently validated distribution.
 * These controls intentionally do not combine dimensions or query source journals.
 */
export function bindProvisionalDistributionControls(root = document) {
    const dimension = root.getElementById('provisionalDimension');
    const search = root.getElementById('provisionalSearch');
    const reset = root.getElementById('resetProvisionalFilters');
    const selection = root.getElementById('provisionalSelection');

    if (!dimension || !search || !reset || !selection) {
        return;
    }

    const apply = () => {
        const selectedDimension = dimension.value;
        const query = normalizedSearchValue(search.value);

        search.disabled = selectedDimension === '';
        if (selectedDimension === '') {
            search.value = '';
            selection.textContent = 'Seleccione una distribución para consultar sus valores precalculados.';
        } else {
            selection.textContent = `Mostrando la distribución: ${dimension.selectedOptions[0]?.textContent || selectedDimension}.`;
        }

        root.querySelectorAll('[data-provisional-distribution]').forEach((panel) => {
            const isSelected = selectedDimension !== '' && panel.dataset.provisionalDistribution === selectedDimension;
            panel.hidden = selectedDimension !== '' && !isSelected;

            panel.querySelectorAll('[data-provisional-row]').forEach((row) => {
                const label = normalizedSearchValue(row.dataset.provisionalLabel);
                row.hidden = isSelected && query !== '' && !label.includes(query);
            });
        });

        root.querySelectorAll('[data-provisional-collection]').forEach((collection) => {
            const hasSelectedPanel = [...collection.querySelectorAll('[data-provisional-distribution]')]
                .some((panel) => panel.dataset.provisionalDistribution === selectedDimension);

            collection.hidden = selectedDimension !== '' && !hasSelectedPanel;
        });
    };

    dimension.addEventListener('change', () => {
        search.value = '';
        apply();
    });
    search.addEventListener('input', apply);
    reset.addEventListener('click', () => {
        dimension.value = '';
        search.value = '';
        apply();
    });

    apply();
}

if (typeof document !== 'undefined') {
    document.addEventListener('DOMContentLoaded', () => bindProvisionalDistributionControls());
}
