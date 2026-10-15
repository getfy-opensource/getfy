/**
 * Pull-to-refresh para o PWA instalado (painel e área de membros).
 *
 * Em modo standalone o navegador não oferece o "puxar para recarregar" nativo (iOS nunca oferece; no painel o
 * overscroll também é desligado para não haver bounce). Aqui o gesto é reimplementado: arrastar para baixo com a
 * página no topo, passar do limite e soltar recarrega a página.
 */

const MAX_OFFSET = 120;
const RESISTANCE = 0.5;

/** App instalado na tela inicial (Android/desktop: display-mode standalone; iOS: navigator.standalone). */
export function isStandaloneDisplay(win) {
    if (!win) return false;
    try {
        if (win.matchMedia?.('(display-mode: standalone)').matches) return true;
    } catch (_) {}
    return win.navigator?.standalone === true;
}

/**
 * O gesto só vale com a janela e todo container rolável acima do toque no topo — senão é rolagem normal
 * (lista dentro de modal, sidebar, etc.). `data-no-pull-refresh` desliga o gesto numa subárvore.
 */
export function isScrolledToTop(target, win) {
    if ((win?.scrollY ?? 0) > 0) return false;
    for (let el = target; el; el = el.parentElement) {
        if (el.hasAttribute?.('data-no-pull-refresh')) return false;
        if ((el.scrollTop ?? 0) > 0) return false;
    }
    return true;
}

/** Deslocamento do indicador para um arrasto de `deltaY` px (com resistência e teto). */
export function pullOffset(deltaY) {
    if (!(deltaY > 0)) return 0;
    return Math.min(MAX_OFFSET, deltaY * RESISTANCE);
}

/**
 * Máquina de estados do gesto, sem DOM: recebe eventos de toque e chama `onChange({ offset, ready, refreshing })`
 * para desenhar o indicador e `onRefresh()` ao soltar depois do limite.
 */
export function createPullToRefresh({ win, onRefresh, onChange = () => {}, isLocked = () => false, threshold = 70 }) {
    let startY = null;
    let pulling = false;
    let offset = 0;
    let refreshing = false;

    const emit = () => onChange({ offset, ready: offset >= threshold, refreshing });

    function reset() {
        startY = null;
        pulling = false;
        offset = 0;
    }

    function start(event) {
        reset();
        if (refreshing || !isStandaloneDisplay(win) || isLocked()) return;
        if (!event.touches || event.touches.length !== 1) return;
        if (!isScrolledToTop(event.target, win)) return;
        startY = event.touches[0].clientY;
    }

    function move(event) {
        if (startY === null) return;
        if (!event.touches || event.touches.length !== 1) {
            cancel();
            return;
        }
        const deltaY = event.touches[0].clientY - startY;
        if (!pulling) {
            if (deltaY < 0) {
                // Começou rolando a página para baixo: não é pull-to-refresh.
                startY = null;
                return;
            }
            if (deltaY === 0) return;
            pulling = true;
        }
        offset = pullOffset(deltaY);
        if (event.cancelable) event.preventDefault();
        emit();
    }

    function end() {
        if (!pulling) {
            reset();
            return;
        }
        const shouldRefresh = offset >= threshold;
        reset();
        if (shouldRefresh) {
            refreshing = true;
            offset = threshold;
            emit();
            onRefresh();
            return;
        }
        emit();
    }

    function cancel() {
        const wasPulling = pulling;
        reset();
        if (wasPulling) emit();
    }

    return { start, move, end, cancel };
}
