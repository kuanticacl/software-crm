<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CircleAlert, Pencil, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import BarChart from '@/components/charts/BarChart.vue';
import FormField from '@/components/FormField.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { usage } from '@/routes/ai';
import { budget as budgetRoute, prices as pricesRoute } from '@/routes/ai/usage';

type Model = { key: string; provider: string | null; model: string | null; calls: number; errors: number; input_tokens: number; output_tokens: number; avg_ms: number; cost: number; priced: boolean; price: { in: number; out: number; source: 'custom' | 'default' } | null };
const props = defineProps<{
    filters: { days: number; provider: string | null };
    providers: string[];
    totals: { calls: number; errors: number; input_tokens: number; output_tokens: number; cost: number; unpriced_calls: number; avg_ms: number };
    daily: { date: string; calls: number; errors: number; input_tokens: number; output_tokens: number; cost: number }[];
    models: Model[];
    features: { feature: string; calls: number; errors: number; input_tokens: number; output_tokens: number; avg_ms: number }[];
    users: { name: string; calls: number; tokens: number }[];
    errors: { id: number; feature: string; provider: string | null; model: string | null; error: string | null; user: string | null; ms: number; at: string }[];
    topErrors: { message: string; count: number; last_at: string }[];
    month: { spent: number; projected: number; budget: number; day: number; days: number };
}>();
defineOptions({ layout: { breadcrumbs: [{ title: 'Consumo y gastos', href: usage() }] } });

const featureLabel: Record<string, string> = {
    agent: 'Agent (chat)', alfred: 'Alfred (sitio web)', transcribe: 'Voz → texto', email_design: 'Diseño de mailings', subjects: 'Asuntos de correo', lead_analysis: 'Análisis de clientes',
    proposal_draft: 'Propuestas: redactar', proposal_improve: 'Propuestas: mejorar', proposal_service: 'Propuestas: servicios', proposal_suggest: 'Propuestas: sugerir', test: 'Pruebas de conexión',
};
const label = (f: string) => featureLabel[f.replace(/_fallback$/, '')] ?? f.replace(/_fallback$/, '').replace(/_/g, ' ');

const usd = (n: number) => (n >= 100 ? `$${n.toFixed(0)}` : n >= 1 ? `$${n.toFixed(2)}` : `$${n.toFixed(n < 0.01 && n > 0 ? 4 : 3)}`);
const num = (n: number) => new Intl.NumberFormat('es-CL').format(Math.round(n));
const compact = (n: number) => new Intl.NumberFormat('es-CL', { notation: 'compact', maximumFractionDigits: 1 }).format(n);
const pct = (a: number, b: number) => (b ? `${((a / b) * 100).toFixed(a / b < 0.1 ? 1 : 0)}%` : '0%');

const go = (patch: Record<string, string | number | null>) => router.get(usage().url, { days: props.filters.days, provider: props.filters.provider, ...patch }, { preserveState: true, preserveScroll: true, replace: true });

const labels = computed(() => props.daily.map((d) => d.date));
const OK = 'var(--color-brand-green, #0d9f85)';
const ERR = '#DC2626';
const IN = '#6366F1';
const OUT = '#F59E0B';
const COST = 'var(--primary)';

const errorRate = computed(() => props.totals.calls ? props.totals.errors / props.totals.calls : 0);
const monthPct = computed(() => (props.month.budget ? Math.min(100, (props.month.spent / props.month.budget) * 100) : 0));
const overBudget = computed(() => props.month.budget > 0 && props.month.projected > props.month.budget);

// ---------------------------------------------------------------- editar precio / presupuesto
const priceFor = ref<Model | null>(null);
const priceForm = useForm({ key: '', input: '' as string | number, output: '' as string | number });
const openPrice = (m: Model) => {
    priceFor.value = m;
    priceForm.defaults({ key: m.key, input: m.price?.in ?? '', output: m.price?.out ?? '' }).reset();
};
const savePrice = () => priceForm.transform((d) => ({ ...d, input: d.input === '' ? null : d.input, output: d.output === '' ? null : d.output })).put(pricesRoute().url, { preserveScroll: true, onSuccess: () => (priceFor.value = null) });
const resetPrice = () => {
    priceForm.input = '';
    priceForm.output = '';
    savePrice();
};

const budgetOpen = ref(false);
const budgetForm = useForm({ budget: props.month.budget || '' });
const saveBudget = () => budgetForm.put(budgetRoute().url, { preserveScroll: true, onSuccess: () => (budgetOpen.value = false) });

const expanded = ref<number | null>(null);
</script>

<template>
    <Head title="Consumo y gastos de IA" />

    <div class="flex flex-col gap-6 p-4 md:p-6">
        <PageHeader title="Consumo y gastos de IA" description="Llamadas, tokens, costo estimado y fallos de todas las funciones de IA (Agent, propuestas, mailings, análisis de clientes, voz).">
            <template #actions>
                <NativeSelect :model-value="String(filters.days)" class="w-36" @update:model-value="(v: string | number | null) => go({ days: Number(v) })">
                    <option value="7">Últimos 7 días</option><option value="30">Últimos 30 días</option><option value="90">Últimos 90 días</option>
                </NativeSelect>
                <NativeSelect v-if="providers.length > 1" :model-value="filters.provider ?? ''" class="w-44" @update:model-value="(v: string | number | null) => go({ provider: v ? String(v) : null })">
                    <option value="">Todos los proveedores</option><option v-for="p in providers" :key="p" :value="p">{{ p }}</option>
                </NativeSelect>
            </template>
        </PageHeader>

        <!-- Resumen -->
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <div class="rounded-2xl border bg-card p-4"><div class="text-xs text-muted-foreground">Gasto estimado</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ usd(totals.cost) }}</div><div class="text-xs text-muted-foreground">USD · últimos {{ filters.days }} días</div></div>
            <div class="rounded-2xl border bg-card p-4"><div class="text-xs text-muted-foreground">Llamadas</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ num(totals.calls) }}</div><div class="text-xs text-muted-foreground">≈ {{ num(totals.calls / filters.days) }} por día</div></div>
            <div class="rounded-2xl border bg-card p-4"><div class="text-xs text-muted-foreground">Tokens</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ compact(totals.input_tokens + totals.output_tokens) }}</div><div class="text-xs text-muted-foreground">{{ compact(totals.input_tokens) }} entrada · {{ compact(totals.output_tokens) }} salida</div></div>
            <div :class="cn('rounded-2xl border bg-card p-4', errorRate > 0.1 ? 'border-destructive/50' : '')"><div class="text-xs text-muted-foreground">Fallos</div><div :class="cn('mt-1 text-2xl font-semibold tabular-nums', errorRate > 0.1 ? 'text-destructive' : '')">{{ num(totals.errors) }}</div><div class="text-xs text-muted-foreground">{{ pct(totals.errors, totals.calls) }} de las llamadas</div></div>
            <div class="rounded-2xl border bg-card p-4"><div class="text-xs text-muted-foreground">Latencia media</div><div class="mt-1 text-2xl font-semibold tabular-nums">{{ (totals.avg_ms / 1000).toFixed(1) }} s</div><div class="text-xs text-muted-foreground">por llamada</div></div>
        </div>

        <div v-if="totals.unpriced_calls > 0" class="flex items-start gap-3 rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
            <TriangleAlert class="mt-0.5 size-4 shrink-0" />
            <p><strong>{{ num(totals.unpriced_calls) }} llamadas no tienen precio</strong> y no suman al gasto estimado. Define el precio por millón de tokens de tu modelo en la tabla «Por modelo» (lápiz) para ver el gasto real.</p>
        </div>

        <!-- Mes en curso -->
        <section class="rounded-2xl border bg-card p-5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="font-semibold">Mes en curso</h2>
                <Button variant="outline" size="sm" @click="budgetOpen = true"><Pencil /> {{ month.budget ? 'Cambiar presupuesto' : 'Definir presupuesto' }}</Button>
            </div>
            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                <div><div class="text-xs text-muted-foreground">Gastado (día {{ month.day }} de {{ month.days }})</div><div class="text-xl font-semibold tabular-nums">{{ usd(month.spent) }}</div></div>
                <div><div class="text-xs text-muted-foreground">Proyección a fin de mes</div><div :class="cn('text-xl font-semibold tabular-nums', overBudget ? 'text-destructive' : '')">{{ usd(month.projected) }}</div></div>
                <div><div class="text-xs text-muted-foreground">Presupuesto mensual</div><div class="text-xl font-semibold tabular-nums">{{ month.budget ? usd(month.budget) : '—' }}</div></div>
            </div>
            <div v-if="month.budget" class="mt-4">
                <div class="h-2 overflow-hidden rounded-full bg-muted"><div :class="cn('h-full rounded-full', monthPct >= 100 ? 'bg-destructive' : monthPct >= 80 ? 'bg-amber-500' : 'bg-brand-green')" :style="{ width: monthPct + '%' }" /></div>
                <p v-if="overBudget" class="mt-2 flex items-center gap-1.5 text-xs text-destructive"><CircleAlert class="size-3.5" /> A este ritmo superarás el presupuesto del mes.</p>
            </div>
        </section>

        <!-- Gráficos -->
        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-2xl border bg-card p-5">
                <h2 class="mb-3 font-semibold">Gasto diario estimado (USD)</h2>
                <BarChart :labels="labels" :series="[{ key: 'cost', label: 'Gasto', color: COST, values: daily.map((d) => d.cost) }]" :format="usd" chart-label="Gasto diario estimado en dólares" />
            </section>
            <section class="rounded-2xl border bg-card p-5">
                <h2 class="mb-3 font-semibold">Llamadas por día</h2>
                <BarChart :labels="labels" :series="[{ key: 'ok', label: 'Correctas', color: OK, values: daily.map((d) => d.calls - d.errors) }, { key: 'err', label: 'Con error', color: ERR, values: daily.map((d) => d.errors) }]" :format="num" chart-label="Llamadas diarias, correctas y con error" />
            </section>
            <section class="rounded-2xl border bg-card p-5 xl:col-span-2">
                <h2 class="mb-3 font-semibold">Tokens por día</h2>
                <BarChart :labels="labels" :series="[{ key: 'in', label: 'Entrada', color: IN, values: daily.map((d) => d.input_tokens) }, { key: 'out', label: 'Salida', color: OUT, values: daily.map((d) => d.output_tokens) }]" :format="compact" :height="170" chart-label="Tokens diarios de entrada y salida" />
            </section>
        </div>

        <!-- Por modelo -->
        <section class="rounded-2xl border bg-card">
            <div class="border-b p-4"><h2 class="font-semibold">Por modelo</h2><p class="text-xs text-muted-foreground">Los proveedores no informan el costo: se estima con tokens × precio por millón. Verifica los precios con tu proveedor y corrígelos con el lápiz.</p></div>
            <Table>
                <TableHeader><TableRow><TableHead>Proveedor / modelo</TableHead><TableHead class="text-right">Llamadas</TableHead><TableHead class="text-right">Fallos</TableHead><TableHead class="text-right">Tokens (ent. / sal.)</TableHead><TableHead class="text-right">Precio USD/1M (ent. / sal.)</TableHead><TableHead class="text-right">Costo est.</TableHead><TableHead class="w-12" /></TableRow></TableHeader>
                <TableBody>
                    <TableRow v-if="!models.length"><TableCell colspan="7" class="py-8 text-center text-muted-foreground">Aún no hay uso de IA en este período.</TableCell></TableRow>
                    <TableRow v-for="m in models" :key="m.key">
                        <TableCell><div class="font-medium">{{ m.model ?? 'modelo predeterminado' }}</div><div class="text-xs text-muted-foreground">{{ m.provider }}</div></TableCell>
                        <TableCell class="text-right tabular-nums">{{ num(m.calls) }}</TableCell>
                        <TableCell :class="cn('text-right tabular-nums', m.errors ? 'text-destructive' : '')">{{ num(m.errors) }}</TableCell>
                        <TableCell class="text-right tabular-nums">{{ compact(m.input_tokens) }} / {{ compact(m.output_tokens) }}</TableCell>
                        <TableCell class="text-right tabular-nums"><template v-if="m.price">{{ m.price.in }} / {{ m.price.out }} <Badge v-if="m.price.source === 'custom'" variant="secondary" class="ml-1">tuyo</Badge></template><Badge v-else variant="outline" class="border-amber-400 text-amber-700">sin precio</Badge></TableCell>
                        <TableCell class="text-right font-medium tabular-nums">{{ m.priced ? usd(m.cost) : '—' }}</TableCell>
                        <TableCell><Button variant="ghost" size="icon-sm" title="Editar precio" @click="openPrice(m)"><Pencil /></Button></TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </section>

        <div class="grid gap-4 xl:grid-cols-2">
            <section class="rounded-2xl border bg-card">
                <div class="border-b p-4"><h2 class="font-semibold">Por función</h2></div>
                <Table>
                    <TableHeader><TableRow><TableHead>Función</TableHead><TableHead class="text-right">Llamadas</TableHead><TableHead class="text-right">Fallos</TableHead><TableHead class="text-right">Tokens</TableHead><TableHead class="text-right">Latencia</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-if="!features.length"><TableCell colspan="5" class="py-6 text-center text-muted-foreground">Sin datos.</TableCell></TableRow>
                        <TableRow v-for="f in features" :key="f.feature">
                            <TableCell class="font-medium">{{ label(f.feature) }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{ num(f.calls) }}</TableCell>
                            <TableCell :class="cn('text-right tabular-nums', f.errors ? 'text-destructive' : '')">{{ num(f.errors) }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{ compact(f.input_tokens + f.output_tokens) }}</TableCell>
                            <TableCell class="text-right tabular-nums">{{ (f.avg_ms / 1000).toFixed(1) }} s</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </section>
            <section class="rounded-2xl border bg-card">
                <div class="border-b p-4"><h2 class="font-semibold">Por usuario</h2></div>
                <Table>
                    <TableHeader><TableRow><TableHead>Usuario</TableHead><TableHead class="text-right">Llamadas</TableHead><TableHead class="text-right">Tokens</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-if="!users.length"><TableCell colspan="3" class="py-6 text-center text-muted-foreground">Sin datos.</TableCell></TableRow>
                        <TableRow v-for="u in users" :key="u.name"><TableCell class="font-medium">{{ u.name }}</TableCell><TableCell class="text-right tabular-nums">{{ num(u.calls) }}</TableCell><TableCell class="text-right tabular-nums">{{ compact(u.tokens) }}</TableCell></TableRow>
                    </TableBody>
                </Table>
            </section>
        </div>

        <!-- Fallos -->
        <section class="rounded-2xl border bg-card">
            <div class="border-b p-4"><h2 class="font-semibold">Fallos</h2><p class="text-xs text-muted-foreground">Errores más frecuentes y los últimos 25 del período (nunca incluyen tu API key).</p></div>
            <div v-if="!errors.length" class="p-8 text-center text-sm text-muted-foreground">Sin fallos en este período 🎉</div>
            <template v-else>
                <ul class="divide-y border-b">
                    <li v-for="e in topErrors" :key="e.message" class="flex items-start gap-3 p-4 text-sm">
                        <Badge variant="destructive" class="shrink-0">{{ e.count }}×</Badge>
                        <div class="min-w-0 flex-1"><div class="break-words">{{ e.message }}</div><div class="text-xs text-muted-foreground">último: {{ formatDateTime(e.last_at) }}</div></div>
                    </li>
                </ul>
                <Table>
                    <TableHeader><TableRow><TableHead>Cuándo</TableHead><TableHead>Función</TableHead><TableHead>Modelo</TableHead><TableHead>Usuario</TableHead><TableHead>Error</TableHead></TableRow></TableHeader>
                    <TableBody>
                        <TableRow v-for="e in errors" :key="e.id" class="cursor-pointer align-top" @click="expanded = expanded === e.id ? null : e.id">
                            <TableCell class="whitespace-nowrap text-muted-foreground">{{ formatDateTime(e.at) }}</TableCell>
                            <TableCell class="whitespace-nowrap">{{ label(e.feature) }}</TableCell>
                            <TableCell class="text-muted-foreground">{{ e.model ?? e.provider }}</TableCell>
                            <TableCell class="whitespace-nowrap text-muted-foreground">{{ e.user ?? 'Sistema' }}</TableCell>
                            <TableCell :class="cn('max-w-md text-destructive', expanded === e.id ? 'whitespace-pre-wrap break-words' : 'truncate')">{{ e.error }}</TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </template>
        </section>
    </div>

    <Dialog :open="!!priceFor" @update:open="(v: boolean) => !v && (priceFor = null)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader><DialogTitle>Precio de {{ priceFor?.model ?? 'modelo predeterminado' }}</DialogTitle><DialogDescription>USD por millón de tokens, según la tabla de precios de tu proveedor. Déjalo vacío y guarda para volver al valor de referencia.</DialogDescription></DialogHeader>
            <div class="grid grid-cols-2 gap-3">
                <FormField label="Entrada (USD / 1M)" :error="priceForm.errors.input"><Input v-model="priceForm.input" type="number" step="0.01" min="0" /></FormField>
                <FormField label="Salida (USD / 1M)" :error="priceForm.errors.output"><Input v-model="priceForm.output" type="number" step="0.01" min="0" /></FormField>
            </div>
            <DialogFooter class="gap-2"><Button variant="ghost" @click="resetPrice">Valor de referencia</Button><Button variant="outline" @click="priceFor = null">Cancelar</Button><Button :disabled="priceForm.processing" @click="savePrice">Guardar</Button></DialogFooter>
        </DialogContent>
    </Dialog>

    <Dialog :open="budgetOpen" @update:open="(v: boolean) => (budgetOpen = v)">
        <DialogContent class="sm:max-w-sm">
            <DialogHeader><DialogTitle>Presupuesto mensual de IA</DialogTitle><DialogDescription>En USD. Te avisamos en esta pantalla si la proyección lo supera. Déjalo en 0 para quitarlo.</DialogDescription></DialogHeader>
            <FormField label="USD por mes" :error="budgetForm.errors.budget"><Input v-model="budgetForm.budget" type="number" step="1" min="0" /></FormField>
            <DialogFooter class="gap-2"><Button variant="outline" @click="budgetOpen = false">Cancelar</Button><Button :disabled="budgetForm.processing" @click="saveBudget">Guardar</Button></DialogFooter>
        </DialogContent>
    </Dialog>
</template>
