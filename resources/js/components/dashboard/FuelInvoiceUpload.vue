<script setup lang="ts">
import { ref } from 'vue';
import { useHttp } from '@inertiajs/vue3';
import type { SyntheticEvent } from 'vue';

const props = defineProps({
  initialInvoices: {
    type: Array as () => Array<{
      id: number;
      status: string;
      invoice_date: string | null;
      total_amount_paid: number | null;
    }>,
    default: () => [],
  },
});

const http = useHttp();
const selectedFile = ref<File | null>(null);
const isUploading = ref(false);
const uploadError = ref<string | null>(null);
const invoices = ref([...props.initialInvoices]);

const dropAreaClass = ref('border-dashed border-border bg-muted/40');

const handleDrop = (event: DragEvent): void => {
  event.preventDefault();
  event.stopPropagation();

  if (event.dataTransfer?.files.length) {
    selectedFile.value = event.dataTransfer.files[0];
    uploadInvoice();
  }
};

const handleFileInput = (event: Event): void => {
  const input = event.target as HTMLInputElement;
  selectedFile.value = input.files?.[0] ?? null;
  if (selectedFile.value) {
    uploadInvoice();
  }
};

const uploadInvoice = async (): Promise<void> => {
  if (!selectedFile.value) {
    return;
  }

  isUploading.value = true;
  uploadError.value = null;

  const formData = new FormData();
  formData.append('image', selectedFile.value);

  try {
    const response = await http.post('/api/v1/fuel-invoices', formData, {
      preserveState: true,
      headers: { 'Content-Type': 'multipart/form-data' },
    });

    invoices.value.unshift(response.props?.invoice ?? response.data?.invoice);
  } catch (error) {
    uploadError.value = 'Error al subir el recibo. Intenta de nuevo.';
  } finally {
    isUploading.value = false;
  }
};

const dragEvents = {
  handleDragOver: (event: DragEvent) => {
    event.preventDefault();
    event.dataTransfer!.dropEffect = 'copy';
    dropAreaClass.value = 'border-primary bg-primary/10';
  },
  handleDragLeave: () => {
    dropAreaClass.value = 'border-dashed border-border bg-muted/40';
  },
};
</script>

<template>
  <div class="rounded-[2rem] border border-border/70 bg-card/95 p-6 shadow-soft">
    <div class="mb-4 flex items-center justify-between gap-4">
      <div>
        <p class="text-sm font-medium uppercase tracking-[0.16em] text-muted-foreground">Recibos de gasolina</p>
        <p class="text-xs text-muted-foreground">Sube recibos y revisa el estado OCR</p>
      </div>
    </div>

    <div
      class="rounded-[1.75rem] border border-dashed border-border/70 bg-background/90 p-8 text-center transition-all duration-200"
      :class="dropAreaClass"
      @dragover="dragEvents.handleDragOver"
      @dragleave="dragEvents.handleDragLeave"
      @drop="handleDrop"
    >
      <p class="text-sm text-muted-foreground">Arrastra y suelta tu recibo aquí o haz clic para seleccionar uno.</p>
      <label class="mt-4 inline-flex cursor-pointer items-center justify-center rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-primary-foreground shadow-sm transition hover:bg-primary/90">
        Seleccionar archivo
        <input type="file" class="hidden" accept="image/*,.pdf" @change="handleFileInput" />
      </label>
      <p v-if="isUploading" class="mt-3 text-sm text-muted-foreground">Subiendo recibo...</p>
      <p v-if="uploadError" class="mt-3 text-sm text-rose-500">{{ uploadError }}</p>
    </div>

    <div class="mt-6 space-y-3">
      <div
        v-for="invoice in invoices"
        :key="invoice.id"
        class="rounded-[1.75rem] border border-border/70 bg-background/90 p-4 shadow-sm"
      >
        <div class="flex flex-wrap items-center justify-between gap-4">
          <p class="font-medium text-foreground">Recibo {{ invoice.id }}</p>
          <span
            class="rounded-full px-3 py-1 text-xs font-semibold"
            :class="{
              'bg-emerald-500/10 text-emerald-600': invoice.status === 'completed',
              'bg-sky-500/10 text-sky-600': invoice.status === 'processing',
              'bg-amber-500/10 text-amber-600': invoice.status === 'pending',
              'bg-rose-500/10 text-rose-600': invoice.status === 'failed',
            }"
          >
            {{ invoice.status }}
          </span>
        </div>
        <div class="mt-3 text-sm text-muted-foreground space-y-1">
          <p>Fecha: {{ invoice.invoice_date ?? 'Pendiente' }}</p>
          <p>Monto: {{ invoice.total_amount_paid !== null ? `$${invoice.total_amount_paid.toFixed(2)}` : 'N/A' }}</p>
        </div>
      </div>
    </div>
  </div>
</template>
