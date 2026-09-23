import type { Tool } from '@modelcontextprotocol/sdk/types.js';
import { readFileSync } from 'node:fs';
import { basename } from 'node:path';
import { client, apiError } from '../client.js';

export const definition: Tool = {
  name: 'upload_file',
  description:
    'Upload a file from the local filesystem and attach it to an existing resource. '
    + 'The file is sent as multipart/form-data. '
    + 'Role "canonical" marks it as the primary file (any previous canonical is demoted). '
    + 'Triggers text extraction and embedding automatically.',
  inputSchema: {
    type: 'object',
    required: ['resource_id', 'file_path'],
    properties: {
      resource_id: { type: 'string', description: 'Resource UUID to attach the file to' },
      file_path:   { type: 'string', description: 'Absolute path to the local file to upload' },
      role: {
        type: 'string',
        description: 'File role: canonical (default) | component | supporting',
        enum: ['canonical', 'component', 'supporting'],
      },
      relation: {
        type: 'string',
        description: 'Relation to the canonical file: derived | rendition | variant | translation | transcript | extracted',
        enum: ['derived', 'rendition', 'variant', 'translation', 'transcript', 'extracted'],
      },
    },
  },
};

export async function handler(args: Record<string, unknown>): Promise<unknown> {
  const { resource_id, file_path, role, relation } = args as {
    resource_id: string;
    file_path: string;
    role?: string;
    relation?: string;
  };

  try {
    const fileBuffer  = readFileSync(file_path);
    const fileName    = basename(file_path);

    // Web FormData/Blob (Node 18+) — fetch sets the multipart boundary itself.
    const form = new FormData();
    // The Laravel endpoint expects the field named 'File' (capital F)
    form.append('File', new Blob([fileBuffer]), fileName);
    form.append('role', role ?? 'canonical');
    if (relation) form.append('relation', relation);

    const res = await client.post(`/resources/${resource_id}/files`, form);

    return res.data?.data ?? { success: true };
  } catch (err) {
    return { error: apiError(err) };
  }
}
