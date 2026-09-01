/**
 * FieldPulse Kenya - Digital Signature Canvas Handler
 * Handles Mouse & Touchscreen signatures for Customer Sign-off
 */

class SignaturePad {
    constructor(canvasElement) {
        this.canvas = canvasElement;
        if (!this.canvas) return;
        this.ctx = this.canvas.getContext('2d');
        this.isDrawing = false;
        this.hasDrawn = false;
        this.lastX = 0;
        this.lastY = 0;

        this.init();
    }

    init() {
        this.resize();
        window.addEventListener('resize', () => this.resize());

        // Context properties
        this.ctx.lineWidth = 2.5;
        this.ctx.lineCap = 'round';
        this.ctx.lineJoin = 'round';
        this.ctx.strokeStyle = '#0f172a';

        // Mouse events
        this.canvas.addEventListener('mousedown', (e) => this.startDrawing(e));
        this.canvas.addEventListener('mousemove', (e) => this.draw(e));
        this.canvas.addEventListener('mouseup', () => this.stopDrawing());
        this.canvas.addEventListener('mouseleave', () => this.stopDrawing());

        // Touch events for Mobile/Tablet
        this.canvas.addEventListener('touchstart', (e) => {
            e.preventDefault();
            const touch = e.touches[0];
            this.startDrawing(touch);
        }, { passive: false });

        this.canvas.addEventListener('touchmove', (e) => {
            e.preventDefault();
            const touch = e.touches[0];
            this.draw(touch);
        }, { passive: false });

        this.canvas.addEventListener('touchend', (e) => {
            e.preventDefault();
            this.stopDrawing();
        }, { passive: false });
    }

    getPos(e) {
        const rect = this.canvas.getBoundingClientRect();
        const scaleX = this.canvas.width / rect.width;
        const scaleY = this.canvas.height / rect.height;
        return {
            x: (e.clientX - rect.left) * scaleX,
            y: (e.clientY - rect.top) * scaleY
        };
    }

    startDrawing(e) {
        this.isDrawing = true;
        const pos = this.getPos(e);
        this.lastX = pos.x;
        this.lastY = pos.y;
    }

    draw(e) {
        if (!this.isDrawing) return;
        const pos = this.getPos(e);

        this.ctx.beginPath();
        this.ctx.moveTo(this.lastX, this.lastY);
        this.ctx.lineTo(pos.x, pos.y);
        this.ctx.stroke();

        this.lastX = pos.x;
        this.lastY = pos.y;
        this.hasDrawn = true;
    }

    stopDrawing() {
        this.isDrawing = false;
    }

    clear() {
        this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
        this.hasDrawn = false;
    }

    isEmpty() {
        return !this.hasDrawn;
    }

    toDataURL(type = 'image/png') {
        return this.canvas.toDataURL(type);
    }

    resize() {
        const rect = this.canvas.getBoundingClientRect();
        // Set display dimensions as internal resolution
        if (rect.width > 0 && rect.height > 0) {
            const temp = this.ctx.getImageData(0, 0, this.canvas.width, this.canvas.height);
            this.canvas.width = rect.width;
            this.canvas.height = rect.height;
            this.ctx.lineWidth = 2.5;
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';
            this.ctx.strokeStyle = '#0f172a';
            if (this.hasDrawn) {
                this.ctx.putImageData(temp, 0, 0);
            }
        }
    }
}
