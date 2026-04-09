/**
 * Image-Textarea Editor
 * Permite pegar capturas de pantalla (imágenes) en campos textarea
 * Versión: 1.0.0
 * 
 * Funciona con divs contenteditable que tienen la clase "imgtext-editor".
 * El contenido HTML (texto + imágenes base64) se sincroniza a un input hidden
 * con id = {div_id}_hidden antes del submit del formulario.
 */

var ImgTextEditor = {

    MAX_IMAGE_WIDTH: 800, // px máximo para redimensionar imágenes pegadas
    MAX_CONTENT_SIZE: 10 * 1024 * 1024, // 10MB máx por campo (considerando MEDIUMTEXT y POST limits)

    init: function() {
        var self = this;
        var editors = document.querySelectorAll('.imgtext-editor');
        
        if (editors.length === 0) return;

        editors.forEach(function(editor) {
            self.setupEditor(editor);
        });

        // Sincronizar contenido antes de enviar cualquier formulario
        var forms = document.querySelectorAll('form');
        forms.forEach(function(form) {
            form.addEventListener('submit', function() {
                self.syncAllEditors();
            });
        });
    },

    setupEditor: function(editor) {
        var self = this;

        // Manejar pegado de imágenes
        editor.addEventListener('paste', function(e) {
            var items = (e.clipboardData || e.originalEvent.clipboardData).items;
            var hasImage = false;

            for (var i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') !== -1) {
                    hasImage = true;
                    e.preventDefault();
                    var file = items[i].getAsFile();
                    self.insertImage(editor, file);
                    break;
                }
            }

            // Si no es imagen, permitir pegar texto normal pero limpiar formato
            if (!hasImage) {
                // Dejar que el navegador maneje el pegado de texto
                // pero limpiar HTML no deseado después
                setTimeout(function() {
                    self.cleanPastedContent(editor);
                    self.syncEditor(editor);
                }, 10);
            }
        });

        // Sincronizar al escribir/modificar
        editor.addEventListener('input', function() {
            self.syncEditor(editor);
        });

        // Manejar drag & drop de imágenes
        editor.addEventListener('dragover', function(e) {
            e.preventDefault();
            editor.style.borderColor = '#2196F3';
        });

        editor.addEventListener('dragleave', function() {
            editor.style.borderColor = '#ccc';
        });

        editor.addEventListener('drop', function(e) {
            e.preventDefault();
            editor.style.borderColor = '#ccc';
            var files = e.dataTransfer.files;
            for (var i = 0; i < files.length; i++) {
                if (files[i].type.indexOf('image') !== -1) {
                    self.insertImage(editor, files[i]);
                }
            }
        });

        // Focus styling
        editor.addEventListener('focus', function() {
            editor.style.borderColor = '#2196F3';
            editor.style.boxShadow = '0 0 3px rgba(33,150,243,0.3)';
        });

        editor.addEventListener('blur', function() {
            editor.style.borderColor = '#ccc';
            editor.style.boxShadow = 'none';
            self.syncEditor(editor);
        });

        // Doble clic en imagen para eliminar (con confirmación)
        editor.addEventListener('dblclick', function(e) {
            if (e.target.tagName === 'IMG') {
                if (confirm('¿Eliminar esta imagen?')) {
                    // Eliminar también el <br> siguiente si existe
                    var nextEl = e.target.nextSibling;
                    if (nextEl && nextEl.tagName === 'BR') {
                        nextEl.parentNode.removeChild(nextEl);
                    }
                    e.target.parentNode.removeChild(e.target);
                    self.syncEditor(editor);
                }
            }
        });

        // Hover visual en imágenes para indicar que se pueden eliminar
        editor.addEventListener('mouseover', function(e) {
            if (e.target.tagName === 'IMG') {
                e.target.style.outline = '2px solid #e74c3c';
                e.target.style.cursor = 'pointer';
                e.target.title = 'Doble clic para eliminar';
            }
        });
        editor.addEventListener('mouseout', function(e) {
            if (e.target.tagName === 'IMG') {
                e.target.style.outline = 'none';
                e.target.style.cursor = '';
                e.target.title = '';
            }
        });

        // Sincronización inicial
        this.syncEditor(editor);
    },

    insertImage: function(editor, file) {
        var self = this;
        var reader = new FileReader();

        reader.onload = function(e) {
            var img = new Image();
            img.onload = function() {
                var dataUrl = e.target.result;

                // Redimensionar si es muy grande
                if (img.width > self.MAX_IMAGE_WIDTH) {
                    dataUrl = self.resizeImage(img, self.MAX_IMAGE_WIDTH);
                }

                var imgEl = document.createElement('img');
                imgEl.src = dataUrl;
                imgEl.style.maxWidth = '100%';
                imgEl.style.height = 'auto';
                imgEl.style.display = 'block';
                imgEl.style.margin = '8px 0';
                imgEl.style.borderRadius = '4px';
                imgEl.style.border = '1px solid #ddd';

                // Insertar en posición del cursor
                var selection = window.getSelection();
                if (selection.rangeCount > 0 && editor.contains(selection.anchorNode)) {
                    var range = selection.getRangeAt(0);
                    range.deleteContents();
                    range.insertNode(imgEl);
                    // Mover cursor después de la imagen
                    range.setStartAfter(imgEl);
                    range.collapse(true);
                    selection.removeAllRanges();
                    selection.addRange(range);
                } else {
                    editor.appendChild(imgEl);
                }

                // Agregar salto de línea después
                var br = document.createElement('br');
                imgEl.parentNode.insertBefore(br, imgEl.nextSibling);

                self.syncEditor(editor);
                
                // Verificar tamaño total del contenido
                var hiddenId = editor.id + '_hidden';
                var hidden = document.getElementById(hiddenId);
                if (hidden && hidden.value.length > self.MAX_CONTENT_SIZE) {
                    // Remover la imagen recién insertada
                    imgEl.parentNode.removeChild(br);
                    imgEl.parentNode.removeChild(imgEl);
                    self.syncEditor(editor);
                    alert('La imagen no se pudo agregar porque el contenido total excede el límite permitido (10MB).\nIntenta usar imágenes más pequeñas o reducir la cantidad.');
                }
            };
            img.src = e.target.result;
        };

        reader.readAsDataURL(file);
    },

    resizeImage: function(img, maxWidth) {
        var canvas = document.createElement('canvas');
        var ratio = maxWidth / img.width;
        canvas.width = maxWidth;
        canvas.height = img.height * ratio;
        var ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
        return canvas.toDataURL('image/png', 0.85);
    },

    cleanPastedContent: function(editor) {
        // Eliminar estilos innecesarios de contenido pegado, preservar imgs
        var imgs = editor.querySelectorAll('img');
        imgs.forEach(function(img) {
            img.style.maxWidth = '100%';
            img.style.height = 'auto';
        });
    },

    syncEditor: function(editor) {
        var hiddenId = editor.id + '_hidden';
        var hidden = document.getElementById(hiddenId);
        if (hidden) {
            hidden.value = editor.innerHTML;
        }
    },

    syncAllEditors: function() {
        var self = this;
        var editors = document.querySelectorAll('.imgtext-editor');
        editors.forEach(function(editor) {
            self.syncEditor(editor);
        });
    }
};

// Auto-inicializar cuando el DOM esté listo
if (typeof jQuery !== 'undefined') {
    jQuery(document).ready(function() {
        ImgTextEditor.init();
    });
} else {
    document.addEventListener('DOMContentLoaded', function() {
        ImgTextEditor.init();
    });
}
