let timer = null;

self.onmessage = function (e) {
    const data = e.data || {};

    if (data.type === 'start') {
        if (timer) return;
        const intervalMs = Number(data.intervalMs) > 0 ? Number(data.intervalMs) : 42;
        timer = setInterval(function () {
            self.postMessage({ type: 'tick' });
        }, intervalMs);
        return;
    }

    if (data.type === 'stop') {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
    }
};
