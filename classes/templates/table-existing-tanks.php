<?php
$results = $results; // inutile mais clarifie
?>
<input type="text" id="tankSearch" placeholder="Rechercher..." style="margin-bottom:10px;padding:5px;width:100%;max-width:300px;" />

<table id="tanksTable" style="width:100%;border-collapse:collapse;">
    <thead>
        <tr>
            <th><?php echo __('Article', 'creation-reservoir'); ?></th>
            <th><?php echo __('Diameter', 'creation-reservoir'); ?></th>
            <th><?php echo __('Material', 'creation-reservoir'); ?></th>
            <th><?php echo __('Volume', 'creation-reservoir'); ?></th>
            <th><?php echo __('Operating pressure', 'creation-reservoir'); ?></th>
            <th><?php echo __('Project', 'creation-reservoir'); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($results as $row): ?>
            <tr>
                <td>
                    <a href="<?= esc_url($row->project->project_url_dev) ?>">
                    <?= esc_html($row->article->Article) ?>
                    </a>
                </td>
                <td><?= esc_html($row->Diameter) ?> mm</td>
                <td><?= esc_html($row->MaterialLabel) ?></td>
                <td><?= esc_html($row->Volume) ?> L</td>
                <td><?= esc_html($row->MaxPressure) ?> bar</td>
                <td>
                    <?php if (!empty($row->project->project_url_dev)): ?>
                        <a href="<?= esc_url($row->project->project_url_dev) ?>">
                            <?= esc_html($row->project->ObjetCommande) ?>
                        </a>
                    <?php else: ?>
                        -
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<script>
document.getElementById('tankSearch').addEventListener('input', function () {
    const val = this.value.toLowerCase();
    document.querySelectorAll('#tanksTable tbody tr').forEach(row => {
        row.style.display = [...row.children].some(td =>
            td.textContent.toLowerCase().includes(val)
        ) ? '' : 'none';
    });
});
</script>
