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
        .postman-op-btn {
            position: absolute;
            top: 8px;
            right: 8px;
            z-index: 2;
            background: #f2994a;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
        }
        .postman-op-btn:hover {
            filter: brightness(0.95);
        }
        /* Leave space for per-operation Postman button */
        .opblock-summary {
            padding-right: 110px !important;
        }
    </style>
</head>
<body>
    <!-- Export Buttons -->
    <div class="export-buttons">
        <button class="export-btn" onclick="exportAllPostman()">
             Export Postman (All)
        </button>
    </div>

    <div id="swagger-ui"></div>

    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5/swagger-ui-standalone-preset.js"></script>
    <script>
        window.onload = function() {
            // Add "Postman" button per API (operation)
            const PostmanExportPlugin = function () {
                return {
                    wrapComponents: {
                        OperationSummary: function (Original, system) {
                            return function (props) {
                                const React = system.React;
                                const exportUrl =
                                    "{{ route('swagger.postman') }}" +
                                    "?path=" + encodeURIComponent(props.path) +
                                    "&method=" + encodeURIComponent(props.method);

                                return React.createElement(
                                    "div",
                                    { style: { position: "relative" } },
                                    React.createElement(Original, props),
                                    React.createElement(
                                        "button",
                                        {
                                            type: "button",
                                            className: "postman-op-btn",
                                            onClick: function (e) {
                                                e.preventDefault();
                                                e.stopPropagation();
                                                window.open(exportUrl, "_blank");
                                            },
                                        },
                                        "Postman"
                                    )
                                );
                            };
                        },
                    },
                };
            };

            const ui = SwaggerUIBundle({
                url: "{{ route('swagger.spec') }}",
                dom_id: '#swagger-ui',
                deepLinking: true,
                presets: [
                    SwaggerUIBundle.presets.apis,
                    SwaggerUIStandalonePreset
                ],
                plugins: [
                    SwaggerUIBundle.plugins.DownloadUrl,
                    PostmanExportPlugin
                ],
                layout: "StandaloneLayout",
            });

            window.ui = ui;
        };

        // Export tất cả
        function exportAllPostman() {
            // 1 click: download ONE Postman Collection file (contains base_url/token variables)
            const downloadJson = async (url, fallbackName) => {
                const res = await fetch(url, { credentials: 'same-origin' });
                if (!res.ok) throw new Error(`Failed to download ${url}: ${res.status}`);

                const blob = await res.blob();

                // Try to parse filename from Content-Disposition
                const cd = res.headers.get('content-disposition') || '';
                const match = cd.match(/filename="([^"]+)"/i);
                const filename = (match && match[1]) ? match[1] : fallbackName;

                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = filename;
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(() => URL.revokeObjectURL(a.href), 2000);
            };

            (async () => {
                try {
                    await downloadJson("{{ route('swagger.postman') }}", "postman-collection-all.json");
                } catch (e) {
                    alert(e?.message || 'Export failed');
                }
            })();
        }
    </script>
</body>
</html>
