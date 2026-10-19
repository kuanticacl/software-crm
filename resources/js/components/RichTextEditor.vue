<script setup lang="ts">
import StarterKit from '@tiptap/starter-kit';
import Placeholder from '@tiptap/extension-placeholder';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { Bold, Braces, Heading3, Italic, Link2, List, ListOrdered, Redo2, RemoveFormatting, Underline as UnderlineIcon, Undo2 } from '@lucide/vue';
import { onBeforeUnmount, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { legacyToHtml } from '@/lib/richText';
import { cn } from '@/lib/utils';

const props = withDefaults(
    defineProps<{
        placeholder?: string;
        minHeight?: number;
        compact?: boolean; // barra reducida (notas rápidas)
        disabled?: boolean;
        variables?: string[]; // variables propias de la plantilla (correos)
        system?: string[];
    }>(),
    { placeholder: 'Escribe aquí…', minHeight: 120 },
);

/** HTML. Si llega un texto antiguo con «markdown mínimo» se convierte al abrir. */
const model = defineModel<string>({ default: '' });

const editor = useEditor({
    content: legacyToHtml(model.value),
    editable: !props.disabled,
    extensions: [
        StarterKit.configure({
            heading: { levels: [3, 4] },
            codeBlock: false,
            code: false,
            horizontalRule: false,
            strike: false,
            link: { openOnClick: false, autolink: true, HTMLAttributes: { rel: 'noopener nofollow', target: '_blank' } },
        }),
        Placeholder.configure({ placeholder: props.placeholder }),
    ],
    editorProps: { attributes: { class: 'rte-content', spellcheck: 'true' } },
    onUpdate: ({ editor }) => {
        const html = editor.isEmpty ? '' : editor.getHTML();
        if (html !== model.value) model.value = html;
    },
});

// Cambios externos (p. ej. texto generado por la IA).
watch(
    () => model.value,
    (v) => {
        const ed = editor.value;
        if (!ed) return;
        const current = ed.isEmpty ? '' : ed.getHTML();
        if ((v ?? '') !== current) ed.commands.setContent(legacyToHtml(v ?? ''), { emitUpdate: false });
    },
);
watch(() => props.disabled, (d) => editor.value?.setEditable(!d));
onBeforeUnmount(() => editor.value?.destroy());

const setLink = () => {
    const ed = editor.value;
    if (!ed) return;
    const prev = ed.getAttributes('link').href as string | undefined;
    const url = window.prompt('Dirección del enlace (https://…)', prev ?? 'https://');
    if (url === null) return;
    if (url.trim() === '' || url.trim() === 'https://') return void ed.chain().focus().unsetLink().run();
    if (!/^https:\/\//i.test(url.trim())) return void window.alert('El enlace debe comenzar con https://');
    ed.chain().focus().extendMarkRange('link').setLink({ href: url.trim() }).run();
};
const insertVar = (key: string) => editor.value?.chain().focus().insertContent(`{{ ${key} }}`).run();

const btn = (active: boolean) => cn('size-8 rounded-lg text-muted-foreground', active && 'bg-primary/10 text-primary');
</script>

<template>
    <div :class="cn('rte rounded-xl border bg-background transition focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/25', disabled && 'opacity-60')">
        <div v-if="editor" class="flex flex-wrap items-center gap-0.5 border-b px-1.5 py-1">
            <Button type="button" variant="ghost" size="icon-sm" :class="btn(editor.isActive('bold'))" title="Negrita (Ctrl+B)" @click="editor.chain().focus().toggleBold().run()"><Bold /></Button>
            <Button type="button" variant="ghost" size="icon-sm" :class="btn(editor.isActive('italic'))" title="Cursiva (Ctrl+I)" @click="editor.chain().focus().toggleItalic().run()"><Italic /></Button>
            <Button v-if="!compact" type="button" variant="ghost" size="icon-sm" :class="btn(editor.isActive('underline'))" title="Subrayado" @click="editor.chain().focus().toggleUnderline().run()"><UnderlineIcon /></Button>
            <span class="mx-1 h-4 w-px bg-border" />
            <Button type="button" variant="ghost" size="icon-sm" :class="btn(editor.isActive('bulletList'))" title="Lista con viñetas" @click="editor.chain().focus().toggleBulletList().run()"><List /></Button>
            <Button type="button" variant="ghost" size="icon-sm" :class="btn(editor.isActive('orderedList'))" title="Lista numerada" @click="editor.chain().focus().toggleOrderedList().run()"><ListOrdered /></Button>
            <Button v-if="!compact" type="button" variant="ghost" size="icon-sm" :class="btn(editor.isActive('heading', { level: 3 }))" title="Subtítulo" @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"><Heading3 /></Button>
            <Button type="button" variant="ghost" size="icon-sm" :class="btn(editor.isActive('link'))" title="Enlace" @click="setLink"><Link2 /></Button>
            <span class="mx-1 h-4 w-px bg-border" />
            <Button type="button" variant="ghost" size="icon-sm" :class="btn(false)" title="Quitar formato" @click="editor.chain().focus().unsetAllMarks().clearNodes().run()"><RemoveFormatting /></Button>
            <Button v-if="!compact" type="button" variant="ghost" size="icon-sm" :class="btn(false)" title="Deshacer" :disabled="!editor.can().undo()" @click="editor.chain().focus().undo().run()"><Undo2 /></Button>
            <Button v-if="!compact" type="button" variant="ghost" size="icon-sm" :class="btn(false)" title="Rehacer" :disabled="!editor.can().redo()" @click="editor.chain().focus().redo().run()"><Redo2 /></Button>
            <DropdownMenu v-if="variables?.length || system?.length">
                <DropdownMenuTrigger as-child><Button type="button" variant="ghost" size="icon-sm" :class="cn(btn(false), 'ml-auto')" title="Insertar variable"><Braces /></Button></DropdownMenuTrigger>
                <DropdownMenuContent align="end" class="max-h-72 w-56 overflow-y-auto">
                    <template v-if="variables?.length">
                        <DropdownMenuLabel>De esta plantilla</DropdownMenuLabel>
                        <DropdownMenuItem v-for="v in variables" :key="v" @select="insertVar(v)"><code class="text-xs">{{ v }}</code></DropdownMenuItem>
                        <DropdownMenuSeparator />
                    </template>
                    <DropdownMenuLabel>Del sistema</DropdownMenuLabel>
                    <DropdownMenuItem v-for="v in system ?? []" :key="v" @select="insertVar(v)"><code class="text-xs">{{ v }}</code></DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
        <EditorContent :editor="editor" :style="{ '--rte-min': `${minHeight}px` }" />
    </div>
</template>

<style>
.rte .rte-content { min-height: var(--rte-min, 120px); padding: 0.65rem 0.8rem; outline: none; font-size: 0.875rem; line-height: 1.6; }
.rte .rte-content > * + * { margin-top: 0.5rem; }
.rte .rte-content p.is-editor-empty:first-child::before { content: attr(data-placeholder); float: left; height: 0; pointer-events: none; color: var(--muted-foreground, #8a8a8a); }
.rte .rte-content ul { list-style: disc; padding-left: 1.25rem; }
.rte .rte-content ol { list-style: decimal; padding-left: 1.25rem; }
.rte .rte-content li::marker { color: #027BB9; }
.rte .rte-content h3 { font-size: 1rem; font-weight: 700; margin-top: 0.75rem; }
.rte .rte-content h4 { font-weight: 600; }
.rte .rte-content a { color: #027BB9; text-decoration: underline; }
.rte .rte-content blockquote { border-left: 3px solid #027BB9; padding-left: 0.75rem; color: #707070; }
</style>
