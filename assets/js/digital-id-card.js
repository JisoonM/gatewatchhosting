/**
 * Digital Student ID Card Component
 * Uses a Canva-designed PNG template as background with dynamic data overlays.
 * Supports drag-and-drop photo upload and photo repositioning.
 */
console.log('[DigitalIdCard] JS file loaded successfully');

class DigitalIdCard {
    static DEFAULT_LAYOUT = {
        photo:     { top: 47.8, left: 32, width: 59.5, height: 37, borderRadius: 13 },
        name:      { top: 70, left: 76, fontSize: 5, color: '#1e293b', fontWeight: '790', maxWidth: 45 },
        studentId: { top: 59, left: 81, fontSize: 4, color: '#1e293b', fontWeight: '700' },
        course:    { top: 94, left: 50, fontSize: 3.8, color: '#ffffff', fontWeight: '800' },
    };

    constructor(container, options) {
        options = options || {};
        this.container = typeof container === 'string' ? document.querySelector(container) : container;
        this.templateSrc = options.templateSrc || '../assets/images/id-card-template.png';
        this.layout = Object.assign({}, DigitalIdCard.DEFAULT_LAYOUT, options.layout || {});
        this.student = options.student || {};
        this.cardEl = null;
        this.overlays = {};
        // Photo upload state
        this._pendingPhotoFile = null;   // File object to upload on save
        this._pendingPhotoDataUrl = null; // preview data URL
        this._photoOffsetX = 0;
        this._photoOffsetY = 0;
        this._photoScale = 1;
        this._isDraggingPhoto = false;
        this._fileInput = null;
        this.onPhotoChange = options.onPhotoChange || null; // callback
    }

    render() {
        if (!this.container) {
            console.error('DigitalIdCard: container not found');
            return this;
        }
        this.container.innerHTML = '';

        this.cardEl = document.createElement('div');
        this.cardEl.style.cssText = 'position:relative; width:100%; max-width:400px; margin:0 auto; border-radius:16px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.18);';

        var tpl = document.createElement('img');
        tpl.src = this.templateSrc;
        tpl.alt = 'ID Card';
        tpl.draggable = false;
        tpl.style.cssText = 'width:100%; display:block; user-select:none;';
        tpl.onerror = function() {
            tpl.style.display = 'none';
            this.cardEl.style.background = 'linear-gradient(135deg,#1e3a5f,#2563eb,#1e40af)';
            this.cardEl.style.paddingBottom = '150%';
            this._recalcFonts();
        }.bind(this);
        tpl.onload = function() {
            this._recalcFonts();
        }.bind(this);
        this.cardEl.appendChild(tpl);

        var ov = document.createElement('div');
        ov.style.cssText = 'position:absolute; top:0; left:0; right:0; bottom:0; pointer-events:none;';
        this.cardEl.appendChild(ov);

        this._addPhoto(ov);
        this._addText(ov, 'name', this.student.name || 'Student Name');
        this._addText(ov, 'studentId', this.student.studentId || 'XX-XXXX-XXX');
        this._addText(ov, 'course', this.student.course || 'Course / Program');

        this.container.appendChild(this.cardEl);

        // Hidden file input for click-to-upload
        this._fileInput = document.createElement('input');
        this._fileInput.type = 'file';
        this._fileInput.accept = 'image/jpeg,image/png';
        this._fileInput.style.display = 'none';
        this._fileInput.onchange = this._onFileSelected.bind(this);
        this.container.appendChild(this._fileInput);

        // Setup drag-drop on the whole card
        this._setupCardDragDrop();

        if (window.ResizeObserver) {
            this._ro = new ResizeObserver(this._recalcFonts.bind(this));
            this._ro.observe(this.cardEl);
        }
        return this;
    }

    // ===== PHOTO AREA =====
    _addPhoto(parent) {
        var L = this.layout.photo;
        if (!L) return;
        var wrap = document.createElement('div');
        var h = L.height || L.width;
        wrap.style.cssText = 'position:absolute; top:'+L.top+'%; left:'+L.left+'%; transform:translate(-50%,-50%); width:'+L.width+'%; height:'+h+'%; border-radius:'+(L.borderRadius||50)+'%; overflow:hidden; background:transparent; pointer-events:auto; cursor:pointer;';
        
        var img = document.createElement('img');
        img.alt = '';
        img.style.cssText = 'position:absolute; top:0; left:0; width:100%; height:100%; object-fit:cover; pointer-events:none;';
        img.src = this.student.profilePicture || '';
        if (!this.student.profilePicture) img.style.display = 'none';
        img.onerror = function(){ img.style.display = 'none'; };

        // Upload hint overlay (shown when no photo)
        var hint = document.createElement('div');
        hint.className = 'photo-upload-hint';
        hint.style.cssText = 'position:absolute; top:0; left:0; width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; background:rgba(226,232,240,0.9); pointer-events:none; transition:opacity 0.2s;';
        hint.innerHTML = '<svg width="24" height="24" fill="none" stroke="#64748b" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg><span style="font-size:10px;color:#64748b;margin-top:4px;font-family:sans-serif;">Drop or click</span>';
        if (this.student.profilePicture) hint.style.opacity = '0';

        wrap.appendChild(img);
        wrap.appendChild(hint);
        parent.appendChild(wrap);
        
        this.overlays.photo = img;
        this._photoWrap = wrap;
        this._photoHint = hint;

        // Click to open file picker
        wrap.addEventListener('click', function(e) {
            e.stopPropagation();
            if (this._fileInput) this._fileInput.click();
        }.bind(this));

        // Photo drag-drop on the photo area specifically
        wrap.addEventListener('dragover', function(e) { e.preventDefault(); e.stopPropagation(); wrap.style.outline = '3px solid #6366f1'; }.bind(this));
        wrap.addEventListener('dragleave', function(e) { e.preventDefault(); wrap.style.outline = 'none'; }.bind(this));
        wrap.addEventListener('drop', function(e) {
            e.preventDefault(); e.stopPropagation();
            wrap.style.outline = 'none';
            var files = e.dataTransfer.files;
            if (files.length > 0) this._handlePhotoFile(files[0]);
        }.bind(this));

        // Mouse drag to reposition photo
        this._setupPhotoDrag(wrap, img);
    }

    // ===== CARD-LEVEL DRAG-DROP =====
    _setupCardDragDrop() {
        if (!this.cardEl) return;
        var card = this.cardEl;
        var self = this;
        card.addEventListener('dragover', function(e) { e.preventDefault(); });
        card.addEventListener('drop', function(e) {
            e.preventDefault();
            var files = e.dataTransfer.files;
            if (files.length > 0) self._handlePhotoFile(files[0]);
        });
    }

    // ===== FILE HANDLING =====
    _onFileSelected(e) {
        var files = e.target.files;
        if (files.length > 0) this._handlePhotoFile(files[0]);
        // Reset so selecting same file triggers change again
        if (this._fileInput) this._fileInput.value = '';
    }

    _handlePhotoFile(file) {
        if (!file.type.match(/^image\/(jpeg|png|jpg)$/)) {
            alert('Only JPG or PNG images are allowed.');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert('Image must be under 5MB.');
            return;
        }
        this._pendingPhotoFile = file;
        var reader = new FileReader();
        reader.onload = function(ev) {
            this._pendingPhotoDataUrl = ev.target.result;
            this._photoOffsetX = 0;
            this._photoOffsetY = 0;
            this._photoScale = 1;
            this._applyPhotoPreview(ev.target.result);
            if (this.onPhotoChange) this.onPhotoChange(file);
        }.bind(this);
        reader.readAsDataURL(file);
    }

    _applyPhotoPreview(dataUrl) {
        var img = this.overlays.photo;
        if (!img) return;
        img.src = dataUrl;
        img.style.display = 'block';
        img.style.objectPosition = '50% 50%';
        if (this._photoHint) this._photoHint.style.opacity = '0';
        // Show delete button if exists
        var delBtn = document.getElementById('deletePhotoBtn');
        if (delBtn) delBtn.style.display = 'inline-block';
    }

    // ===== PHOTO REPOSITIONING (mouse drag inside frame) =====
    _setupPhotoDrag(wrap, img) {
        var self = this;
        var startX, startY, startOX, startOY;

        function onMouseDown(e) {
            // Only reposition if there's a photo
            if (!self._pendingPhotoDataUrl && !self.student.profilePicture) return;
            e.preventDefault();
            e.stopPropagation();
            self._isDraggingPhoto = true;
            startX = e.clientX || (e.touches && e.touches[0].clientX);
            startY = e.clientY || (e.touches && e.touches[0].clientY);
            startOX = self._photoOffsetX;
            startOY = self._photoOffsetY;
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
            document.addEventListener('touchmove', onMouseMove, {passive:false});
            document.addEventListener('touchend', onMouseUp);
        }
        function onMouseMove(e) {
            if (!self._isDraggingPhoto) return;
            e.preventDefault();
            var cx = e.clientX || (e.touches && e.touches[0].clientX);
            var cy = e.clientY || (e.touches && e.touches[0].clientY);
            var dx = cx - startX;
            var dy = cy - startY;
            // Movement is relative to wrap size
            var wrapW = wrap.offsetWidth || 1;
            self._photoOffsetX = Math.max(-50, Math.min(50, startOX + (dx / wrapW) * 100));
            self._photoOffsetY = Math.max(-50, Math.min(50, startOY + (dy / wrapW) * 100));
            img.style.objectPosition = (50 + self._photoOffsetX) + '% ' + (50 + self._photoOffsetY) + '%';
        }
        function onMouseUp() {
            self._isDraggingPhoto = false;
            document.removeEventListener('mousemove', onMouseMove);
            document.removeEventListener('mouseup', onMouseUp);
            document.removeEventListener('touchmove', onMouseMove);
            document.removeEventListener('touchend', onMouseUp);
        }

        wrap.addEventListener('mousedown', onMouseDown);
        wrap.addEventListener('touchstart', onMouseDown, {passive:false});

        // Scroll to zoom
        wrap.addEventListener('wheel', function(e) {
            if (!self._pendingPhotoDataUrl && !self.student.profilePicture) return;
            e.preventDefault();
            e.stopPropagation();
            var delta = e.deltaY > 0 ? -0.1 : 0.1;
            self._photoScale = Math.max(1, Math.min(3, self._photoScale + delta));
            img.style.transform = 'scale(' + self._photoScale + ')';
        }, {passive:false});
    }

    // ===== TEXT OVERLAYS =====
    _addText(parent, key, text) {
        var L = this.layout[key];
        if (!L) return;
        var el = document.createElement('div');
        var wrap = L.maxWidth ? 'white-space:normal; max-width:'+L.maxWidth+'%; word-wrap:break-word; line-height:1.2;' : 'white-space:nowrap;';
        el.style.cssText = 'position:absolute; top:'+L.top+'%; left:'+L.left+'%; transform:translateX(-50%); font-weight:'+(L.fontWeight||'400')+'; color:'+(L.color||'#1e293b')+'; text-align:center; font-family:Segoe UI,system-ui,-apple-system,sans-serif; letter-spacing:0.02em; font-size:14px; '+wrap;
        el.textContent = text;
        parent.appendChild(el);
        this.overlays[key] = el;
    }

    _recalcFonts() {
        if (!this.cardEl) return;
        var w = this.cardEl.offsetWidth;
        if (!w) return;
        for (var key in this.overlays) {
            if (key === 'photo') continue;
            var L = this.layout[key];
            if (L && L.fontSize) {
                this.overlays[key].style.fontSize = (L.fontSize / 100 * w) + 'px';
            }
        }
    }

    // ===== PUBLIC API =====
    update(fields) {
        if (fields.name != null && this.overlays.name) {
            this.student.name = fields.name;
            this.overlays.name.textContent = fields.name || 'Student Name';
        }
        if (fields.studentId != null && this.overlays.studentId) {
            this.student.studentId = fields.studentId;
            this.overlays.studentId.textContent = fields.studentId || 'XX-XXXX-XXX';
        }
        if (fields.course != null && this.overlays.course) {
            this.student.course = fields.course;
            this.overlays.course.textContent = fields.course || 'Course / Program';
        }
        if (fields.profilePicture !== undefined && this.overlays.photo) {
            this.student.profilePicture = fields.profilePicture;
            if (fields.profilePicture) {
                this.overlays.photo.src = fields.profilePicture;
                this.overlays.photo.style.display = 'block';
                if (this._photoHint) this._photoHint.style.opacity = '0';
            } else {
                this.overlays.photo.style.display = 'none';
                if (this._photoHint) this._photoHint.style.opacity = '1';
            }
        }
    }

    /** Remove the photo (pending or existing) */
    removePhoto() {
        this._pendingPhotoFile = null;
        this._pendingPhotoDataUrl = null;
        this._photoOffsetX = 0;
        this._photoOffsetY = 0;
        this._photoScale = 1;
        this.student.profilePicture = null;
        if (this.overlays.photo) {
            this.overlays.photo.style.display = 'none';
            this.overlays.photo.style.objectPosition = '50% 50%';
            this.overlays.photo.style.transform = '';
        }
        if (this._photoHint) this._photoHint.style.opacity = '1';
        var delBtn = document.getElementById('deletePhotoBtn');
        if (delBtn) delBtn.style.display = 'none';
    }

    /** Returns the pending File object (or null) */
    getPendingPhotoFile() {
        return this._pendingPhotoFile;
    }

    /** Returns true if there's a new photo to upload */
    hasPendingPhoto() {
        return !!this._pendingPhotoFile;
    }

    animateEntrance() {
        if (!this.cardEl) return;
        this.cardEl.style.transition = 'none';
        this.cardEl.style.opacity = '0';
        this.cardEl.style.transform = 'scale(0.92) translateY(16px)';
        var card = this.cardEl;
        setTimeout(function() {
            card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
            card.style.opacity = '1';
            card.style.transform = 'scale(1) translateY(0)';
        }, 30);
    }

    destroy() {
        if (this._ro) { this._ro.disconnect(); this._ro = null; }
        if (this.container) this.container.innerHTML = '';
        this.cardEl = null;
        this.overlays = {};
        this._pendingPhotoFile = null;
        this._pendingPhotoDataUrl = null;
    }
}

window.DigitalIdCard = DigitalIdCard;
