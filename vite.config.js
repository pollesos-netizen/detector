import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

export default defineConfig({
  base: "./", // 추가
  plugins: [react()],
  worker: {
    format: "es",
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks: (id) => {
          if (id.includes("pdf.worker")) return "pdfworker";
        },
      },
    },
  },
});