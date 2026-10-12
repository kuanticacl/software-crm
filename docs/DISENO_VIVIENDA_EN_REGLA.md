# Guía de diseño · Vivienda en Regla

Sistema visual del CRM (`crm.viviendaenregla.cl`) para replicarlo tal cual en el sitio web: colores, tipografía, botones, formularios, tarjetas, logo y reglas de uso. Estilo: **minimalista, cálido y elegante** — blanco cálido, oro rosa como único acento, mucho aire y tipografía serif solo en los títulos.

---

## 1. Principios

1. **Un solo acento.** El oro rosa guía la acción (botones, enlaces, estados activos). El resto es neutro cálido.
2. **Aire y calma.** Márgenes generosos, bordes finos de 1 px, sombras casi imperceptibles.
3. **Serif para titular, sans para leer.** Cormorant Garamond en títulos; Jost en todo lo demás.
4. **Bordes redondeados suaves.** Botones en píldora, tarjetas con radio amplio.
5. **Contraste accesible.** Texto sobre fondo ≥ 4.5:1; el botón principal (blanco sobre `#A9606B`) cumple 4.6:1.

---

## 2. Paleta de color

### 2.1 Colores de marca

| Nombre | HEX | Uso |
| --- | --- | --- |
| **Oro rosa (acento)** | `#A9606B` | Botón principal, enlaces, íconos activos, foco |
| **Oro rosa intenso** | `#8A4A55` | Hover/pressed del acento, texto sobre fondos tinte |
| **Oro rosa claro** | `#D9A0A5` | Acento en modo oscuro, detalles, líneas decorativas |
| **Oro rosa marca (logo)** | `#B76E79` | Isotipo, tagline del logo, ilustraciones |
| **Champagne** | `#C9A66B` | Detalle secundario (etiquetas, estrellas, divisores) |
| **Rosa pálido (tinte)** | `#F6E7E4` | Fondo de elementos activos, chips, hover suave |
| **Arena** | `#F6EFEB` | Barra lateral, secciones alternas |
| **Blanco cálido** | `#FBF8F6` | Fondo general de la página |
| **Blanco** | `#FFFFFF` | Tarjetas, campos, modales |
| **Carbón cálido** | `#3B2F2F` | Texto principal y títulos |
| **Taupe** | `#7A6A68` | Texto secundario, ayudas, placeholders |
| **Borde** | `#EBE0DA` | Bordes de tarjetas y separadores |
| **Borde de campo** | `#E3D6CF` | Bordes de inputs y selects |

Degradado de marca (isotipo y detalles): `linear-gradient(135deg, #D9A89E 0%, #B76E79 55%, #9C5560 100%)`.
Degradado de portada/hero: `linear-gradient(120deg, #F6EFEB 0%, #EFD9D3 55%, #E3B5AE 100%)`.

### 2.2 Estados y datos

| Nombre | HEX | Uso |
| --- | --- | --- |
| Éxito | `#4F8F7A` | Confirmaciones, "pagado", "ganado" |
| Aviso | `#C9A66B` (texto `#9A4B00` sobre `#FFA165` al 20 %) | Pendientes, advertencias |
| Error | `#C0392B` | Errores, eliminar |
| Información | `#7D8FA3` | Datos neutros |
| Gráficos | `#A9606B` · `#D4A5A0` · `#8C7B75` · `#5B4B4D` · `#C9A66B` | Series 1 a 5 |

### 2.3 Variables CSS (copiar y pegar)

```css
:root {
  /* Superficies */
  --background: #fbf8f6;
  --foreground: #3b2f2f;
  --card: #ffffff;
  --card-foreground: #3b2f2f;
  --popover: #ffffff;
  --popover-foreground: #3b2f2f;
  --secondary: #f3ece8;
  --secondary-foreground: #3b2f2f;
  --muted: #f1eae6;
  --muted-foreground: #7a6a68;

  /* Acento */
  --primary: #a9606b;
  --primary-foreground: #ffffff;
  --accent: #f6e7e4;
  --accent-foreground: #8a4a55;
  --ring: #c98b93;

  /* Líneas y estados */
  --border: #ebe0da;
  --input: #e3d6cf;
  --destructive: #c0392b;
  --destructive-foreground: #ffffff;
  --success: #4f8f7a;

  /* Barra lateral / secciones alternas */
  --sidebar: #f6efeb;
  --sidebar-foreground: #4a3b3c;
  --sidebar-accent: #ecdfd9;
  --sidebar-border: #e6d8d1;

  /* Forma */
  --radius: 0.6rem;      /* 9,6 px: campos y elementos pequeños */
  --radius-card: 1rem;   /* 16 px: tarjetas */
  --radius-pill: 9999px; /* botones y chips */

  /* Tipografía */
  --font-sans: 'Jost', ui-sans-serif, system-ui, sans-serif;
  --font-serif: 'Cormorant Garamond', Georgia, 'Times New Roman', serif;
}

/* Modo oscuro (opcional en el sitio) */
.dark {
  --background: #1d1718;  --foreground: #f3e9e6;
  --card: #271f20;        --card-foreground: #f3e9e6;
  --popover: #271f20;     --popover-foreground: #f3e9e6;
  --secondary: #322829;   --secondary-foreground: #f3e9e6;
  --muted: #322829;       --muted-foreground: #b4a29f;
  --primary: #d9a0a5;     --primary-foreground: #2b1b1e;
  --accent: #3a2b2e;      --accent-foreground: #ecc2c3;
  --ring: #d9a0a5;
  --border: #3a2e2f;      --input: #443637;
  --destructive: #e5554a;
  --sidebar: #171112;     --sidebar-foreground: #dccfcb;
  --sidebar-accent: #271f20; --sidebar-border: #2f2526;
}
```

### 2.4 Contraste verificado

| Combinación | Ratio | Uso |
| --- | --- | --- |
| `#3B2F2F` sobre `#FBF8F6` | ≈ 12.5 : 1 | Texto principal |
| `#7A6A68` sobre `#FFFFFF` | ≈ 5.1 : 1 | Texto secundario |
| `#FFFFFF` sobre `#A9606B` | ≈ 4.6 : 1 | Botón principal |
| `#8A4A55` sobre `#F6E7E4` | ≈ 5.6 : 1 | Chips y elementos activos |

---

## 3. Tipografía

### 3.1 Familias

| Rol | Fuente | Pesos | Carga |
| --- | --- | --- | --- |
| **Títulos / marca** | **Cormorant Garamond** (serif) | 500, 600, 700 | Google Fonts o Bunny Fonts |
| **Texto / interfaz** | **Jost** (sans geométrica) | 400, 500, 600, 700 | Google Fonts o Bunny Fonts |

```html
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Jost:wght@400;500;600;700&display=swap" rel="stylesheet">
<!-- Alternativa sin Google: https://fonts.bunny.net/css?family=cormorant-garamond:500,600,700|jost:400,500,600,700 -->
```

### 3.2 Escala

| Estilo | Fuente | Tamaño (escritorio / móvil) | Peso | Interlineado | Tracking |
| --- | --- | --- | --- | --- | --- |
| Hero (H1 de portada) | Cormorant Garamond | 64 px / 40 px | 600 | 1.05 | -0.01em |
| H1 | Cormorant Garamond | 48 px / 34 px | 600 | 1.1 | -0.01em |
| H2 | Cormorant Garamond | 36 px / 28 px | 600 | 1.15 | -0.005em |
| H3 | Cormorant Garamond | 26 px / 22 px | 600 | 1.2 | 0 |
| H4 / subtítulo | Jost | 18 px | 500 | 1.35 | 0 |
| Cuerpo | Jost | 16–17 px | 400 | 1.65 | 0 |
| Cuerpo pequeño | Jost | 14 px | 400 | 1.55 | 0 |
| Etiqueta (overline) | Jost | 12 px, MAYÚSCULAS | 500 | 1.3 | 0.18em |
| Botón | Jost | 14–15 px | 500 | 1 | 0.01em |
| Legal / nota | Jost | 12 px | 400 | 1.5 | 0 |

Reglas: un solo H1 por página; los títulos van en `--foreground`; las etiquetas overline en `--primary`; los párrafos con un máximo de 68 caracteres de ancho.

```css
body { font-family: var(--font-sans); color: var(--foreground); background: var(--background); line-height: 1.65; -webkit-font-smoothing: antialiased; }
h1, h2, h3 { font-family: var(--font-serif); font-weight: 600; letter-spacing: -0.01em; color: var(--foreground); }
.overline { font: 500 12px/1.3 var(--font-sans); text-transform: uppercase; letter-spacing: .18em; color: var(--primary); }
```

---

## 4. Componentes

### 4.1 Botones

Forma **píldora** (`border-radius: 9999px`), tipografía Jost 500, sin mayúsculas. Transición suave (150 ms).

| Variante | Fondo | Texto | Borde | Hover |
| --- | --- | --- | --- | --- |
| **Primario** | `#A9606B` | `#FFFFFF` | — | fondo `#A9606B` al 90 % (≈ `#B2707A`) |
| **Secundario** | `#F3ECE8` | `#3B2F2F` | — | fondo al 80 % |
| **Contorno** | `#FBF8F6` | `#3B2F2F` | `1px #EBE0DA` | fondo `#F6E7E4`, texto `#8A4A55` |
| **Fantasma** | transparente | `#3B2F2F` | — | fondo `#F6E7E4`, texto `#8A4A55` |
| **Enlace** | transparente | `#A9606B` | — | subrayado |
| **Destructivo** | `#C0392B` | `#FFFFFF` | — | fondo al 90 % |

| Tamaño | Alto | Padding horizontal | Fuente |
| --- | --- | --- | --- |
| Pequeño | 32 px | 12 px | 13–14 px |
| Normal | 36 px | 16 px | 14 px |
| Grande (CTA) | 44 px | 28 px | 15–16 px |

Foco visible: `box-shadow: 0 0 0 3px rgba(201,139,147,.5)` (anillo `--ring` al 50 %). Deshabilitado: opacidad 50 %, sin eventos. Ícono + texto: separación 8 px, ícono de 16 px con trazo de 1.5–2 px.

```css
.btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; height:36px; padding:0 16px;
  font:500 14px/1 var(--font-sans); border:0; border-radius:9999px; cursor:pointer; transition:all .15s ease; }
.btn-primary { background:#a9606b; color:#fff; box-shadow:0 1px 2px rgba(59,47,47,.08); }
.btn-primary:hover { background:rgba(169,96,107,.9); }
.btn-outline { background:#fbf8f6; color:#3b2f2f; border:1px solid #ebe0da; }
.btn-outline:hover { background:#f6e7e4; color:#8a4a55; }
.btn-ghost { background:transparent; color:#3b2f2f; }
.btn-ghost:hover { background:#f6e7e4; color:#8a4a55; }
.btn-lg { height:44px; padding:0 28px; font-size:15px; }
.btn:focus-visible { outline:none; box-shadow:0 0 0 3px rgba(201,139,147,.5); }
```

### 4.2 Campos de formulario

- Alto 36 px (44 px en formularios largos del sitio), radio `--radius` (≈ 10 px), borde `1px #E3D6CF`, fondo blanco, texto `#3B2F2F`, placeholder `#7A6A68`.
- **Foco:** borde `#C98B93` + anillo `0 0 0 3px rgba(201,139,147,.5)`.
- **Error:** borde `#C0392B`, anillo rojo al 20 %, mensaje en 12–13 px `#C0392B`.
- **Etiqueta:** Jost 500, 14 px, `#3B2F2F`, 8 px sobre el campo. **Ayuda:** 12 px `#7A6A68` bajo el campo.
- Selects y fechas con el mismo estilo; textarea con mínimo 3 líneas.
- Interruptores (switch): activo `#A9606B`, inactivo `#E3D6CF`.

### 4.3 Tarjetas

- Fondo `#FFFFFF`, borde `1px #EBE0DA`, radio 16 px (`rounded-2xl`), sombra `0 1px 2px rgba(0,0,0,.03)`.
- Padding 20–32 px. Título de tarjeta en Jost 600 16–18 px (o Cormorant 26 px en tarjetas destacadas).
- Hover (si es clicable): borde `#D9A0A5` y sombra `0 8px 24px rgba(169,96,107,.10)`.

### 4.4 Chips y etiquetas

Píldora de 22–26 px de alto, texto 12 px / 500. Neutra: fondo `#F1EAE6`, texto `#7A6A68`. Acento: fondo `#F6E7E4`, texto `#8A4A55`. Éxito: fondo `rgba(79,143,122,.12)`, texto `#3C7A66`. Aviso: fondo `rgba(255,161,101,.20)`, texto `#9A4B00`. Error: fondo `rgba(192,57,43,.10)`, texto `#C0392B`.

### 4.5 Navegación y barra lateral

- Fondo `#F6EFEB`, borde `#E6D8D1`, texto `#4A3B3C`.
- Elemento activo: fondo `#ECDFD9`, texto `#7E4450`, radio 10 px.
- Encabezados de grupo: Jost 500, 12 px, `#7A6A68`.
- Barra superior del sitio: fondo `#FBF8F6` al 95 % con blur, borde inferior `#EBE0DA`, enlaces en Jost 500 15 px; activo en `#A9606B`.

### 4.6 Tablas y listas

Encabezado Jost 500, 12 px, MAYÚSCULAS, tracking 0.06em, `#7A6A68`; filas separadas por `1px #EBE0DA`; fila en hover `#FBF8F6`; cifras con `font-variant-numeric: tabular-nums`.

### 4.7 Modales

Fondo blanco, radio 16 px, borde `#EBE0DA`, sombra `0 24px 64px rgba(59,47,47,.18)`, velo `rgba(59,47,47,.45)`. Título en Cormorant Garamond 28 px.

### 4.8 Sombras y radios

| Token | Valor |
| --- | --- |
| Sombra tarjeta | `0 1px 2px rgba(0,0,0,.03)` |
| Sombra botón primario | `0 1px 2px rgba(59,47,47,.08)` |
| Sombra elevada | `0 8px 24px rgba(169,96,107,.10)` |
| Radio campo | 10 px |
| Radio tarjeta | 16 px |
| Radio hero/portada | 28 px |
| Radio botón/chip | 9999 px |

### 4.9 Espaciado

Base de 4 px: 4 · 8 · 12 · 16 · 20 · 24 · 32 · 48 · 64 · 96. Secciones del sitio con 80–112 px de padding vertical en escritorio (56 px en móvil); contenedor máximo de 1152 px con 24 px de margen lateral.

---

## 5. Secciones del sitio

### 5.1 Hero (portada)

- Fondo `#FBF8F6` con **imagen de arquitectura** (interior luminoso, materiales cálidos) a la derecha, o degradado de portada; velo de arena a la izquierda: `linear-gradient(90deg, #FBF8F6 0%, rgba(251,248,246,.94) 42%, rgba(251,248,246,0) 74%)`.
- Overline en oro rosa, H1 Cormorant 64 px, bajada Jost 18 px `#7A6A68` (máx. 520 px), dos botones: **Primario grande** ("Cuéntanos de tu proyecto") y **Contorno grande** ("Ver servicios").
- Radio 28 px si el hero va dentro de un contenedor con margen.

### 5.2 Servicios

Tarjetas de 3 columnas (1 en móvil) con ícono de línea fina (trazo 1.5 px, color `#A9606B`) sobre un círculo `#F6E7E4` de 44 px, título Cormorant 26 px, texto Jost 15 px `#7A6A68` y enlace "Ver más →" en oro rosa. Servicios: **Regularizaciones · Planos · Diseño de interiores (departamentos, casas, oficinas, retail, stands) · Gestión de obra**.

### 5.3 Llamado a la acción final

Franja con degradado de portada, título Cormorant 44 px centrado, botón primario grande y, debajo, correo y teléfono en Jost 15 px.

### 5.4 Pie de página

Fondo `#F6EFEB`, borde superior `#E6D8D1`, logo a 160 px, enlaces en `#7A6A68` que cambian a `#A9606B` al pasar el cursor, texto legal de 12 px.

### 5.5 Fotografía e iconografía

- Fotos cálidas, luz natural, poco contraste, sin filtros fuertes; esquinas de 16–28 px.
- Íconos de línea (Lucide o similar), trazo 1.5–2 px, color acento o `#3B2F2F`.
- Evitar sombras duras, degradados saturados y más de un color de acento por pantalla.

---

## 6. Logo

Isotipo: casa de línea fina con una "V" en el interior, trazo con degradado oro rosa. Wordmark: **Vivienda en Regla** en Cormorant Garamond 600, color `#3B2F2F`, y debajo el tagline `ARQUITECTURA · REGULARIZACIONES` en Jost 500, MAYÚSCULAS, tracking 0.3em, color `#B76E79`.

| Archivo (en el repositorio, `public/brand/`) | Uso |
| --- | --- |
| `vivienda-logo.png` / `vivienda-logo-dark.png` | Fondos claros (960 × 192, fondo transparente) |
| `vivienda-logo-white.png` | Fondos oscuros o sobre foto |
| `resources/js/components/BrandLogo.vue` | Logo en SVG vivo (hereda el color de texto) |
| `resources/js/components/AppLogoIcon.vue` | Isotipo en SVG |

SVG del isotipo (viewBox 64 × 64):

```svg
<svg viewBox="0 0 64 64" fill="none" stroke="url(#g)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round">
  <defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="#D9A89E"/><stop offset=".55" stop-color="#B76E79"/><stop offset="1" stop-color="#9C5560"/>
  </linearGradient></defs>
  <path d="M8 31 32 9l24 22"/><path d="M14 27v26h36V27"/><path d="M24 53V40l8 9 8-9v13"/>
</svg>
```

Zona de respeto: el alto de la "V" alrededor del logo. Tamaño mínimo: 120 px de ancho (isotipo solo: 24 px). No deformar, no recolorear fuera de la paleta, no usar sobre fondos con poco contraste.

---

## 7. Variables para Tailwind (si el sitio lo usa)

```js
// tailwind.config.js
export default {
  theme: {
    extend: {
      colors: {
        background: '#FBF8F6', foreground: '#3B2F2F', card: '#FFFFFF',
        primary: { DEFAULT: '#A9606B', foreground: '#FFFFFF', dark: '#8A4A55', light: '#D9A0A5' },
        accent: { DEFAULT: '#F6E7E4', foreground: '#8A4A55' },
        sand: '#F6EFEB', champagne: '#C9A66B', muted: { DEFAULT: '#F1EAE6', foreground: '#7A6A68' },
        border: '#EBE0DA', input: '#E3D6CF', ring: '#C98B93', success: '#4F8F7A', destructive: '#C0392B',
      },
      fontFamily: { sans: ['Jost', 'ui-sans-serif', 'system-ui', 'sans-serif'], serif: ['"Cormorant Garamond"', 'Georgia', 'serif'] },
      borderRadius: { card: '1rem', hero: '1.75rem' },
      boxShadow: { card: '0 1px 2px rgba(0,0,0,.03)', lift: '0 8px 24px rgba(169,96,107,.10)' },
    },
  },
}
```

---

## 8. Checklist para traspasar el diseño

- [ ] Cargar Cormorant Garamond (500–700) y Jost (400–700).
- [ ] Definir las variables CSS de la sección 2.3.
- [ ] Títulos en serif; todo lo demás en Jost.
- [ ] Botones en píldora con el acento `#A9606B` (texto blanco).
- [ ] Campos con borde `#E3D6CF`, foco `#C98B93` y anillo suave.
- [ ] Tarjetas con borde fino, radio 16 px y sombra casi nula.
- [ ] Un solo color de acento por pantalla; resto neutro cálido.
- [ ] Logo en PNG/SVG según el fondo; favicon con el isotipo sobre `#FBF8F6`.
- [ ] Verificar contraste y estados de foco en teclado.

> Fuente de verdad en el código: `resources/css/app.css` (variables), `resources/js/components/ui/*` (botones, campos, tarjetas) y `resources/js/components/BrandLogo.vue`.
