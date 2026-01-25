<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ config('swagger.title', 'API Documentation') }}</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui.css">
    <style>
        html {
            box-sizing: border-box;
            overflow: -moz-scrollbars-vertical;
            overflow-y: scroll;
        }
        *, *:before, *:after {
            box-sizing: inherit;
        }
        body {
            margin: 0;
            padding: 0;
        }
        .export-buttons {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            gap: 10px;
        }
        .export-btn {
            background: #4990e2;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
        }
        .export-btn:hover {
            background: #357abd;
        }
        .export-btn.secondary {
            background: #49cc90;
        }
        .export-btn.secondary:hover {
            background: #3aa876;
        }
    </style>
</head>
<body>
    <!-- Export Buttons -->
    <div class="export-buttons">
        <button class="export-btn" onclick="exportAllPostman()">
            📦 Export All to Postman
        </button>
        <button class="export-btn secondary" onclick="exportByTag()">
            🏷️ Export by Tag
        </button>
        <button class="export-btn" style="background:#f2994a" onclick="exportPostmanEnvironment()">
            🌍 Export Postman Environment
        </button>
    </div>

    <div id="swagger-ui"></div>

    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
    <script>
        let specData = null;

        window.onload = function() {
            const ui = SwaggerUIBundle({
                url: "{{ route('swagger.spec') }}",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl
                ],
                layout: "StandaloneLayout",
                onComplete: function() {
                    // Lấy spec data sau khi load
                    fetch("{{ route('swagger.spec') }}")
                        .then(res => res.json())
                        .then(data => {
                            specData = data;
                        });
                }
            });

            window.ui = ui;
        };

        // Export tất cả
        function exportAllPostman() {
            window.open("{{ route('swagger.postman') }}", '_blank');
        }

        function exportPostmanEnvironment() {
            window.open("{{ route('swagger.postman.environment') }}", '_blank');
        }

        // Export theo tag
        function exportByTag() {
            if (!specData) {
                alert('Please wait for API spec to load...');
                return;
            }

            // Lấy danh sách tags
            const tags = new Set();
            Object.values(specData.paths || {}).forEach(methods => {
                Object.values(methods).forEach(operation => {
                    (operation.tags || []).forEach(tag => tags.add(tag));
                });
            });

            if (tags.size === 0) {
                alert('No tags found!');
                return;
            }

            // Tạo modal chọn tag
            const tagList = Array.from(tags).map(tag => 
                `<label style="display: block; margin: 10px 0;">
                    <input type="radio" name="tag" value="${tag}"> ${tag}
                </label>`
            ).join('');

            const modal = document.createElement('div');
            modal.innerHTML = `
                <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; align-items: center; justify-content: center;">
                    <div style="background: white; padding: 30px; border-radius: 8px; max-width: 400px; width: 90%;">
                        <h3 style="margin-top: 0;">Select Tag to Export</h3>
                        ${tagList}
                        <div style="margin-top: 20px; text-align: right;">
                            <button onclick="this.closest('div').parentElement.parentElement.remove()" 
                                style="background: #ccc; border: none; padding: 8px 16px; margin-right: 10px; border-radius: 4px; cursor: pointer;">
                                Cancel
                            </button>
                            <button onclick="doExportTag()" 
                                style="background: #49cc90; color: white; border: none; padding: 8px 16px; border-radius: 4px; cursor: pointer;">
                                Export
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        function doExportTag() {
            const selectedTag = document.querySelector('input[name="tag"]:checked');
            if (!selectedTag) {
                alert('Please select a tag!');
                return;
            }

            window.open("{{ route('swagger.postman') }}?tag=" + encodeURIComponent(selectedTag.value), '_blank');
            document.querySelector('div[style*="position: fixed"]').parentElement.remove();
        }
    </script>
</body>
</html>
