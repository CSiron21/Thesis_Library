/**
 * Interactive Background - Lavalamp / Organic Blobs Effect
 */
class LavalampBackground {
    constructor() {
        this.canvas = document.getElementById('bg-canvas');
        this.ctx = this.canvas.getContext('2d');
        this.blobs = [];
        this.mouse = { x: null, y: null };
        this.config = {
            blobCount: 8, // More blobs for more activity
            colors: [
                'rgba(128, 0, 0, 0.35)',  // HAU Maroon
                'rgba(255, 215, 0, 0.25)', // HAU Gold
                'rgba(160, 0, 0, 0.25)',   // Light Maroon
                'rgba(100, 0, 0, 0.35)',   // Dark Maroon
                'rgba(255, 230, 128, 0.2)' // Light Gold
            ]
        };

        this.init();
        this.animate();
        this.bindEvents();
    }

    init() {
        this.resize();
        this.blobs = [];
        for (let i = 0; i < this.config.blobCount; i++) {
            this.blobs.push({
                x: Math.random() * this.canvas.width,
                y: Math.random() * this.canvas.height,
                radius: Math.random() * 200 + 150,
                color: this.config.colors[i % this.config.colors.length],
                vx: (Math.random() - 0.5) * 1,
                vy: (Math.random() - 0.5) * 1,
                originalRadius: Math.random() * 200 + 150
            });
        }
    }

    resize() {
        this.canvas.width = window.innerWidth;
        this.canvas.height = window.innerHeight;
    }

    bindEvents() {
        window.addEventListener('resize', () => this.init());
        window.addEventListener('mousemove', (e) => {
            this.mouse.x = e.x;
            this.mouse.y = e.y;
        });
    }

    draw() {
        // Subtle background base
        this.ctx.fillStyle = '#f8fafc';
        this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);

        this.blobs.forEach(blob => {
            // Organic movement
            blob.x += blob.vx;
            blob.y += blob.vy;

            // Soft wall bounce
            if (blob.x < -blob.radius) blob.x = this.canvas.width + blob.radius;
            if (blob.x > this.canvas.width + blob.radius) blob.x = -blob.radius;
            if (blob.y < -blob.radius) blob.y = this.canvas.height + blob.radius;
            if (blob.y > this.canvas.height + blob.radius) blob.y = -blob.radius;

            // Mouse Interaction - Blobs scale and follow slightly
            if (this.mouse.x !== null) {
                let dx = blob.x - this.mouse.x;
                let dy = blob.y - this.mouse.y;
                let dist = Math.sqrt(dx * dx + dy * dy);
                if (dist < 400) {
                    blob.radius = blob.originalRadius + (400 - dist) * 0.2;
                    blob.x += (this.mouse.x - blob.x) * 0.005;
                    blob.y += (this.mouse.y - blob.y) * 0.005;
                } else {
                    blob.radius += (blob.originalRadius - blob.radius) * 0.05;
                }
            }

            // Draw Blob with Sharper Gradient
            let gradient = this.ctx.createRadialGradient(
                blob.x, blob.y, 0,
                blob.x, blob.y, blob.radius
            );
            gradient.addColorStop(0, blob.color);
            gradient.addColorStop(0.85, blob.color); // Solid until 85% of the radius
            gradient.addColorStop(1, 'rgba(248, 250, 252, 0)'); // Sharp fade at the edge

            this.ctx.fillStyle = gradient;
            this.ctx.beginPath();
            this.ctx.arc(blob.x, blob.y, blob.radius, 0, Math.PI * 2);
            this.ctx.fill();
        });
    }

    animate() {
        this.draw();
        requestAnimationFrame(() => this.animate());
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new LavalampBackground();
});
