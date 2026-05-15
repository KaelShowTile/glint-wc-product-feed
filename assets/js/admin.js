jQuery(document).ready(function($) {
    if (!$('#gw-field-mappings-ui').length) return;

    let dataSourceOptions = {
        'wc': {},
        'category': {},
        'taxonomy': {},
        'acf': {}
    };

    const typeOptionsHtml = `
        <option value="">Select Type</option>
        <option value="wc">WooCommerce</option>
        <option value="category">Product Category</option>
        <option value="taxonomy">Taxonomy</option>
        <option value="acf">ACF</option>
        <option value="custom_meta">Custom Meta</option>
        <option value="static">Static Value</option>
    `;

    const fixedFieldsList = [
        'g:id', 'g:title', 'g:description', 'g:link', 'g:product_type', 'g:image_link', 
        'g:condition', 'g:checkout_link_template', 'g:availability', 'g:price', 'g:sale_price'
    ];

    let currentData = {
        fixed: [],
        custom: [],
        additional_images: [],
        product_details: []
    };

    // Load initial data
    try {
        let val = $('#gw_field_mappings_input').val();
        if (val) {
            currentData = JSON.parse(val);
        }
    } catch(e) {
        console.error("Failed to parse field mappings JSON");
    }

    // Ensure fixed fields exist
    let existingFixed = {};
    if (currentData.fixed) {
        currentData.fixed.forEach(f => existingFixed[f.name] = f);
    }
    currentData.fixed = fixedFieldsList.map(name => {
        return existingFixed[name] || { name: name, type: '', source: '' };
    });
    if (!currentData.custom) currentData.custom = [];
    if (!currentData.additional_images) currentData.additional_images = [];
    if (!currentData.product_details) currentData.product_details = [];

    // Fetch data sources
    function fetchSources() {
        return $.when(
            $.post(gw_feed_admin.ajax_url, { action: 'gw_feed_get_wc_fields', nonce: gw_feed_admin.nonce }, function(res) {
                if(res.success) dataSourceOptions['wc'] = res.data;
            }),
            $.post(gw_feed_admin.ajax_url, { action: 'gw_feed_get_categories', nonce: gw_feed_admin.nonce }, function(res) {
                if(res.success) dataSourceOptions['category'] = res.data;
            }),
            $.post(gw_feed_admin.ajax_url, { action: 'gw_feed_get_taxonomies', nonce: gw_feed_admin.nonce }, function(res) {
                if(res.success) dataSourceOptions['taxonomy'] = res.data;
            }),
            $.post(gw_feed_admin.ajax_url, { action: 'gw_feed_get_acf_fields', nonce: gw_feed_admin.nonce }, function(res) {
                if(res.success) dataSourceOptions['acf'] = res.data;
            })
        );
    }

    function renderUI() {
        let html = '';

        // 1. Fixed Fields
        html += '<div class="gw-feed-section"><h4>1. Fixed Fields</h4><div id="gw-fixed-fields">';
        currentData.fixed.forEach((f, index) => {
            html += renderFieldRow('fixed', index, f, true);
        });
        html += '</div></div>';

        // 2. Custom Fields
        html += '<div class="gw-feed-section"><h4>2. Custom Fields</h4><div id="gw-custom-fields">';
        currentData.custom.forEach((f, index) => {
            html += renderFieldRow('custom', index, f, false);
        });
        html += '</div><button type="button" class="button gw-add-btn" data-group="custom">Add Custom Field</button></div>';

        // 3. Additional Images
        html += '<div class="gw-feed-section"><h4>3. Additional Images</h4><div id="gw-additional-images">';
        currentData.additional_images.forEach((f, index) => {
            f.name = 'g:additional_image_link';
            html += renderFieldRow('additional_images', index, f, true);
        });
        html += '</div><button type="button" class="button gw-add-btn" data-group="additional_images">Add Additional Image</button></div>';

        // 4. Product Details
        html += '<div class="gw-feed-section"><h4>4. Product Details</h4><div id="gw-product-details">';
        currentData.product_details.forEach((pd, index) => {
            html += renderProductDetailRow(index, pd);
        });
        html += '</div><button type="button" class="button gw-add-btn" data-group="product_details">Add Product Detail</button></div>';

        $('#gw-field-mappings-ui').html(html);
        updateAllSourceFields();
    }

    function renderFieldRow(group, index, data, isNameFixed, prefix = '') {
        let nameAttr = `${group}[${index}][name]`;
        let typeAttr = `${group}[${index}][type]`;
        let sourceAttr = `${group}[${index}][source]`;

        if (prefix) {
            nameAttr = `${prefix}[name]`;
            typeAttr = `${prefix}[type]`;
            sourceAttr = `${prefix}[source]`;
        }

        let nameHtml = isNameFixed 
            ? `<input type="text" value="${data.name}" readonly style="background:#eee;">
               <input type="hidden" class="gw-name" value="${data.name}">`
            : `<input type="text" class="gw-name" value="${data.name || ''}" placeholder="Field Name">`;

        let removeBtn = (group === 'fixed' || (prefix && isNameFixed)) ? '' : `<button type="button" class="button gw-remove-btn">Remove</button>`;

        return `
            <div class="gw-field-row" data-group="${group}" data-index="${index}">
                <div class="gw-col"><label>Field Name</label>${nameHtml}</div>
                <div class="gw-col"><label>Data Source Type</label>
                    <select class="gw-type" data-val="${data.type || ''}">${typeOptionsHtml}</select>
                </div>
                <div class="gw-col"><label>Data Source Field</label>
                    <div class="gw-source-wrapper" data-val="${data.source || ''}"></div>
                </div>
                ${removeBtn}
            </div>
        `;
    }

    function renderProductDetailRow(index, data) {
        let sn = data.section_name || {};
        let an = data.attribute_name || {};
        let av = data.attribute_value || {};
        
        return `
            <div class="gw-pd-row" data-index="${index}">
                <div class="gw-pd-sub-row">
                    <div style="width:100px;"><strong>Section Name</strong></div>
                    ${renderFieldRow('product_details', index, {name:'section_name', type: sn.type, source: sn.source}, true, `product_details[${index}][section_name]`)}
                </div>
                <div class="gw-pd-sub-row">
                    <div style="width:100px;"><strong>Attribute Name</strong></div>
                    ${renderFieldRow('product_details', index, {name:'attribute_name', type: an.type, source: an.source}, true, `product_details[${index}][attribute_name]`)}
                </div>
                <div class="gw-pd-sub-row">
                    <div style="width:100px;"><strong>Attribute Value</strong></div>
                    ${renderFieldRow('product_details', index, {name:'attribute_value', type: av.type, source: av.source}, true, `product_details[${index}][attribute_value]`)}
                </div>
                <button type="button" class="button gw-remove-pd-btn">Remove Product Detail</button>
            </div>
        `;
    }

    function updateAllSourceFields() {
        $('.gw-type').each(function() {
            let val = $(this).attr('data-val');
            if (val) {
                $(this).val(val);
                updateSourceField($(this));
            }
        });
    }

    function updateSourceField($typeSelect) {
        let type = $typeSelect.val();
        let $wrapper = $typeSelect.closest('.gw-field-row').find('.gw-source-wrapper');
        let currentSourceVal = $wrapper.attr('data-val') || '';
        
        let html = '';
        if (type === 'custom_meta' || type === 'static') {
            html = `<input type="text" class="gw-source" value="${currentSourceVal}" placeholder="Enter value">`;
        } else if (['wc', 'category', 'taxonomy', 'acf'].includes(type)) {
            let options = dataSourceOptions[type] || {};
            html = `<select class="gw-source"><option value="">Select Field</option>`;
            for (let key in options) {
                let selected = (currentSourceVal === key) ? 'selected' : '';
                html += `<option value="${key}" ${selected}>${options[key]}</option>`;
            }
            html += `</select>`;
        } else {
            html = `<input type="text" class="gw-source" value="" readonly>`;
        }
        $wrapper.html(html);
    }

    // Events
    $('#gw-field-mappings-ui').on('change', '.gw-type', function() {
        $(this).attr('data-val', $(this).val());
        updateSourceField($(this));
        saveData();
    });

    $('#gw-field-mappings-ui').on('change input', '.gw-name, .gw-source', function() {
        if ($(this).hasClass('gw-source')) {
            $(this).parent().attr('data-val', $(this).val());
        }
        saveData();
    });

    $('#gw-field-mappings-ui').on('click', '.gw-add-btn', function() {
        saveData();
        let group = $(this).data('group');
        if (group === 'custom') {
            currentData.custom.push({name:'', type:'', source:''});
        } else if (group === 'additional_images') {
            currentData.additional_images.push({name:'g:additional_image_link', type:'', source:''});
        } else if (group === 'product_details') {
            currentData.product_details.push({
                section_name: {type:'', source:''},
                attribute_name: {type:'', source:''},
                attribute_value: {type:'', source:''}
            });
        }
        renderUI();
        saveData();
    });

    $('#gw-field-mappings-ui').on('click', '.gw-remove-btn', function() {
        saveData();
        let $row = $(this).closest('.gw-field-row');
        let group = $row.data('group');
        let index = $row.data('index');
        if (group) {
            currentData[group].splice(index, 1);
            renderUI();
            saveData();
        }
    });

    $('#gw-field-mappings-ui').on('click', '.gw-remove-pd-btn', function() {
        saveData();
        let $row = $(this).closest('.gw-pd-row');
        let index = $row.data('index');
        currentData.product_details.splice(index, 1);
        renderUI();
        saveData();
    });

    function saveData() {
        let newData = { fixed: [], custom: [], additional_images: [], product_details: [] };

        $('#gw-fixed-fields .gw-field-row').each(function() {
            newData.fixed.push(getRowData($(this)));
        });
        $('#gw-custom-fields .gw-field-row').each(function() {
            newData.custom.push(getRowData($(this)));
        });
        $('#gw-additional-images .gw-field-row').each(function() {
            newData.additional_images.push(getRowData($(this)));
        });
        $('#gw-product-details .gw-pd-row').each(function() {
            let $rows = $(this).find('.gw-field-row');
            newData.product_details.push({
                section_name: getRowData($rows.eq(0)),
                attribute_name: getRowData($rows.eq(1)),
                attribute_value: getRowData($rows.eq(2))
            });
        });

        currentData = newData;
        $('#gw_field_mappings_input').val(JSON.stringify(newData));
    }

    function getRowData($row) {
        let name = $row.find('.gw-name').val();
        if (name === undefined || name === null || name === '') {
            name = $row.find('.gw-name').attr('value') || '';
        }
        
        let type = $row.find('.gw-type').val();
        if (type === undefined || type === null) {
            type = $row.find('.gw-type').attr('data-val') || '';
        }

        let $source = $row.find('.gw-source');
        let source = '';
        if ($source.length) {
            source = $source.val() || '';
        } else {
            source = $row.find('.gw-source-wrapper').attr('data-val') || '';
        }

        return {
            name: name,
            type: type,
            source: source
        };
    }

    // Init
    $('#gw-field-mappings-ui').html('<p>Loading data sources...</p>');
    fetchSources().done(function() {
        renderUI();
    });

    // Generate Now logic
    let progressTimer;
    $('#gw-generate-now').on('click', function(e) {
        e.preventDefault();
        let postId = $(this).data('post-id');
        let $btn = $(this);
        let $status = $('#gw-generate-status');
        
        // Save form first
        $('#publish').click(); 
        
        // Wait a bit to let save finish, then trigger. Actually WP submit reloads the page.
        // Let's just do ajax post and show progress. If they have unsaved changes, they will be lost if not saved first.
        // Alert user if needed.
        if (confirm('Make sure you have saved your settings before generating. Continue?')) {
            $btn.prop('disabled', true);
            $status.text('Starting...');
            $.post(gw_feed_admin.ajax_url, {
                action: 'gw_feed_generate_now',
                nonce: gw_feed_admin.nonce,
                post_id: postId
            }, function(res) {
                if(res.success) {
                    startPolling(postId);
                } else {
                    $status.text('Failed to start.');
                    $btn.prop('disabled', false);
                }
            });
        }
    });

    function startPolling(postId) {
        clearInterval(progressTimer);
        progressTimer = setInterval(function() {
            $.post(gw_feed_admin.ajax_url, {
                action: 'gw_feed_check_progress',
                nonce: gw_feed_admin.nonce,
                post_id: postId
            }, function(res) {
                if(res.success) {
                    $('#gw-generate-status').text(res.data.message);
                    if(res.data.status === 'completed' || res.data.status === 'failed') {
                        clearInterval(progressTimer);
                        $('#gw-generate-now').prop('disabled', false);
                    }
                }
            });
        }, 3000);
    }
});
