<?php
defined('ABSPATH') || exit;
/** Commentaire ouvert + modèle de commentaire d'un réservoir (affichés sous Logistics / Classification). Variable : $data */
?>
<div class="ispag-modal-grid ispag-tank-comments" style="margin-top: 20px;">
        <?php if (current_user_can('manage_order')): ?>
        <div class="ispag-field" style="flex: 1; min-width: 250px;">
             <div class="field-group" style="margin-bottom: 15px;">
                <div id="ispag-article-template-wrapper" style="margin-bottom: 15px;">
                    <label for="ispag-article-template-select"><strong><?php esc_html_e('Article comment template', 'creation-reservoir'); ?></strong></label>
                    <div style="display: flex; gap: 10px;">
                        <select id="ispag-article-template-select" style="flex: 1;">
                            <option value=""><?php esc_html_e('-- Select a template --', 'ispag-crm'); ?></option>
                            <?php
                            $repo = new ISPAG_Template_Repository();
                            $current_user_id = get_current_user_id();
                            $folders = $repo->get_folders($current_user_id);
                            $templates = $repo->get_templates_for_user($current_user_id, '', 'article_comment');

                            // foreach ($folders as $folder) :
                            //     echo '<optgroup label="' . esc_attr($folder->name) . '">';
                            //     foreach ($templates as $tpl) {
                            //         if ($tpl->folder_id == $folder->id) {
                            //             echo '<option value="' . esc_attr($tpl->id) . '">' . esc_html($tpl->name) . '</option>';
                            //         }
                            //     }
                            //     echo '</optgroup>';
                            // endforeach;
                            foreach ($folders as $folder) {
                                // 1. Filtrer ou vérifier s'il y a des templates pour ce dossier
                                $folder_templates = array_filter($templates, function($tpl) use ($folder) {
                                    return $tpl->folder_id == $folder->id;
                                });

                                // 2. Si le dossier ne contient aucun template, on passe au suivant
                                if (empty($folder_templates)) {
                                    continue;
                                }

                                // 3. Sinon, on affiche le dossier (ex: optgroup) et ses templates
                                echo '<optgroup label="' . esc_attr($folder->name) . '">';
                                foreach ($folder_templates as $tpl) {
                                    echo '<option value="' . esc_attr($tpl->id) . '">' . esc_html($tpl->name) . '</option>';
                                }
                                echo '</optgroup>';
                            }

                            echo '<optgroup label="' . esc_attr__('Other', 'ispag-crm') . '">';
                            foreach ($templates as $tpl) {
                                if (empty($tpl->folder_id)) {
                                    echo '<option value="' . esc_attr($tpl->id) . '">' . esc_html($tpl->name) . '</option>';
                                }
                            }
                            echo '</optgroup>';
                            ?>
                        </select>
                        <button type="button" id="ispag-apply-article-template" class="button button-secondary">
                            <?php esc_html_e('Apply', 'ispag-crm'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; // template de commentaire : manage_order uniquement ?>
        
        <div class="ispag-field" style="flex: 1; min-width: 250px;">
                
            <label><strong><?php echo __('Open comment', 'creation-reservoir'); ?></strong></label>
            <p class="description" style="font-size: 0.85em; color: #666; margin-top: 2px; margin-bottom: 5px;">
                <?php echo __('will be inserted into the item description', 'creation-reservoir'); ?>
            </p>
            <textarea id="tank-open-comment" name="tank[openComment]" style="width: 100%;"><?= esc_attr($data['conception']->openComment ?? '') ?></textarea>
            
        </div>
    </div>
