<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ html: string; device: 'desktop' | 'mobile'; selectedId?: string | null; selectable?: boolean }>();
const emit = defineEmits<{ select: [string] }>();

const frame = ref<HTMLIFrameElement | null>(null);
const ready = ref(false);

// El iframe se carga una sola vez; el contenido se actualiza por mensajes (conserva el scroll y no parpadea).
const shell = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style id="qb-head"></style>
<style>[data-bid]{cursor:pointer}[data-bid]:hover{outline:1px dashed #A9606B;outline-offset:-1px}[data-bid][data-selected]{outline:2px solid #A9606B;outline-offset:-2px}</style></head>
<body></body>
<script>
window.addEventListener('message', function (e) {
  var d = e.data || {};
  if (d.type !== 'render') return;
  var doc = new DOMParser().parseFromString(d.html, 'text/html');
  document.getElementById('qb-head').textContent = Array.from(doc.querySelectorAll('style')).map(function (s) { return s.textContent; }).join('\\n');
  document.body.setAttribute('style', doc.body.getAttribute('style') || '');
  document.body.innerHTML = doc.body.innerHTML;
  document.querySelectorAll('[data-selected]').forEach(function (n) { n.removeAttribute('data-selected'); });
  if (d.selected) { var el = document.querySelector('[data-bid="' + d.selected + '"]'); if (el) el.setAttribute('data-selected', ''); }
  if (d.selectable) { document.body.dataset.selectable = '1'; }
});
document.addEventListener('click', function (e) {
  var a = e.target.closest('a'); if (a) e.preventDefault();
  if (!document.body.dataset.selectable) return;
  var n = e.target.closest('[data-bid]');
  if (n) parent.postMessage({ type: 'qb-select', id: n.getAttribute('data-bid') }, '*');
});
<\/script></html>`;

const push = () => {
    if (!ready.value) return;
    frame.value?.contentWindow?.postMessage({ type: 'render', html: props.html, selected: props.selectedId, selectable: props.selectable }, '*');
};

const onLoad = () => {
    ready.value = true;
    push();
};

const onMessage = (e: MessageEvent) => {
    if (e.source === frame.value?.contentWindow && e.data?.type === 'qb-select') emit('select', e.data.id);
};

onMounted(() => window.addEventListener('message', onMessage));
onBeforeUnmount(() => window.removeEventListener('message', onMessage));
watch(() => [props.html, props.selectedId, props.selectable], push);
</script>

<template>
    <div class="flex h-full justify-center overflow-auto bg-[repeating-conic-gradient(#f0f0f0_0%_25%,#fafafa_0%_50%)] bg-[length:20px_20px] p-4">
        <iframe
            ref="frame"
            :srcdoc="shell"
            sandbox="allow-scripts"
            title="Vista previa del correo"
            :class="cn('h-full min-h-[480px] rounded-lg border bg-white shadow-lg transition-all', device === 'mobile' ? 'w-[390px]' : 'w-full max-w-[760px]')"
            @load="onLoad"
        />
    </div>
</template>
