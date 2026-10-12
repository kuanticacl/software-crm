<script setup lang="ts">
import { Check, Copy, ShieldAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

const props = defineProps<{ slug: string; variables: string[]; sample: Record<string, string>; endpoint: string }>();

const vars = computed(() => Object.fromEntries(props.variables.map((k) => [k, props.sample[k] || `valor_${k}`])));
const json = computed(() => JSON.stringify({ template: props.slug, to: { email: 'persona@ejemplo.cl', name: 'María González' }, variables: vars.value }, null, 2));
const url = computed(() => `${props.endpoint}/emails/send`);

const snippets = computed(() => ({
    curl: `curl -X POST ${url.value} \\
  -H "Authorization: Bearer TU_API_KEY" \\
  -H "Content-Type: application/json" \\
  -d '${json.value}'`,
    js: `// Ejecuta esto en TU servidor (Node, edge function…). Nunca en el navegador.
const res = await fetch('${url.value}', {
  method: 'POST',
  headers: {
    Authorization: 'Bearer ' + process.env.INTEGRALEADS_API_KEY,
    'Content-Type': 'application/json',
  },
  body: JSON.stringify(${json.value.replace(/\n/g, '\n  ')}),
});
console.log(res.status, await res.json());`,
    php: `<?php
// Ejemplo para el backend de tu landing (PHP / WordPress)
$payload = ${phpArray(JSON.parse(json.value))};

$ch = curl_init('${url.value}');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . getenv('INTEGRALEADS_API_KEY'),
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
]);
$response = curl_exec($ch);
echo curl_getinfo($ch, CURLINFO_HTTP_CODE), ' ', $response;`,
    query: `# También acepta los datos por query string (útil para webhooks de herramientas no-code)
curl -X POST "${url.value}?template=${props.slug}&to=persona@ejemplo.cl&to_name=María&${props.variables.map((k) => `${k}=${encodeURIComponent(vars.value[k])}`).join('&')}" \\
  -H "Authorization: Bearer TU_API_KEY"`,
}));

function phpArray(v: unknown, indent = 0): string {
    const pad = '    '.repeat(indent + 1);
    const end = '    '.repeat(indent);
    if (Array.isArray(v)) return `[${v.map((x) => phpArray(x, indent + 1)).join(', ')}]`;
    if (v && typeof v === 'object') {
        return `[\n${Object.entries(v).map(([k, x]) => `${pad}'${k}' => ${phpArray(x, indent + 1)},`).join('\n')}\n${end}]`;
    }
    return typeof v === 'string' ? `'${v.replace(/'/g, "\\'")}'` : String(v);
}

const copied = ref<string | null>(null);
const copy = async (key: string) => {
    await navigator.clipboard.writeText((snippets.value as Record<string, string>)[key]);
    copied.value = key;
    setTimeout(() => (copied.value = null), 1500);
};
const tabs: [string, string][] = [['curl', 'cURL'], ['js', 'Node / JS'], ['php', 'PHP'], ['query', 'Query string']];
</script>

<template>
    <div class="grid gap-3">
        <p class="flex items-start gap-2 rounded-xl border border-[#FFA165]/50 bg-[#FFA165]/10 p-3 text-xs text-[#7A3A00]">
            <ShieldAlert class="mt-0.5 size-4 shrink-0" />
            La API key da permiso para enviar correos: úsala solo desde un servidor (tu backend, una función serverless, Zapier/Make). Nunca la pegues en el JavaScript público de una landing.
        </p>
        <Tabs default-value="curl">
            <TabsList class="flex-wrap">
                <TabsTrigger v-for="[k, label] in tabs" :key="k" :value="k">{{ label }}</TabsTrigger>
            </TabsList>
            <TabsContent v-for="[k] in tabs" :key="k" :value="k" class="mt-2">
                <div class="relative rounded-xl bg-[#1e1e1e] p-3">
                    <Button variant="ghost" size="icon-sm" class="absolute top-2 right-2 text-white/70 hover:bg-white/10 hover:text-white" @click="copy(k)"><Check v-if="copied === k" /><Copy v-else /></Button>
                    <pre class="max-h-72 overflow-auto pr-8 text-[11px] leading-relaxed text-[#e6e6e6]">{{ (snippets as Record<string, string>)[k] }}</pre>
                </div>
            </TabsContent>
        </Tabs>
    </div>
</template>
