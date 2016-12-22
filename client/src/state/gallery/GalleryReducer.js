import deepFreeze from 'deep-freeze-strict';
import GALLERY from './GalleryActionTypes';

const initialState = {
  count: 0, // The number of files in the current view
  editorFields: [], // The input fields for editing files. Hardcoded until form field schema is implemented.
  file: null,
  files: [],
  focus: false,
  path: null, // The current location path the app is on
  selectedFiles: [],
  page: 0,
  errorMessage: null,
};

/**
 * Reducer for the `assetAdmin.gallery` state key.
 *
 * @param object state
 * @param object action - The dispatched action.
 * @param string action.type - Name of the dispatched action.
 * @param object [action.payload] - Optional data passed with the action.
 */
export default function galleryReducer(state = initialState, action) {
  switch (action.type) {
    case GALLERY.ADD_FILES: {
      const nextFilesState = []; // Clone the state.files array

      action.payload.files.forEach(payloadFile => {
        let fileInState = false;

        state.files.forEach(stateFile => {
          // Check if each file given is already in the state
          if (stateFile.id === payloadFile.id) {
            fileInState = true;
          }
        });

        // Only add the file if it isn't already in the state
        if (!fileInState) {
          nextFilesState.push(payloadFile);
        }
      });

      return deepFreeze(Object.assign({}, state, {
        count: typeof action.payload.count !== 'undefined' ? action.payload.count : state.count,
        files: nextFilesState.concat(state.files),
      }));
    }

    case GALLERY.LOAD_FILE_SUCCESS: {
      const oldFile = state.files.find(file => file.id === action.payload.id);
      if (oldFile) {
        const updatedFile = Object.assign({}, oldFile, action.payload.file);

        return deepFreeze(Object.assign({}, state, {
          files: state.files.map(
            file => (file.id === updatedFile.id ? updatedFile : file)
          ),
        }));
      } else if (state.folder.id === action.payload.id) {
        return deepFreeze(Object.assign({}, state, {
          folder: Object.assign({}, state.folder, action.payload.file),
        }));
      }
      return state;
    }

    case GALLERY.UNLOAD_FOLDER: {
      return Object.assign({}, state, {
        files: [],
        count: 0,
      });
    }

    case GALLERY.SELECT_FILES: {
      let selectedFiles = null;

      if (action.payload.ids === null) {
        // No param was passed, so select everything.
        selectedFiles = state.files.map(file => file.id);
      } else {
        // We're dealing with an array if ids to select.
        selectedFiles = state.selectedFiles.concat(
          action.payload.ids.filter(id => state.selectedFiles.indexOf(id) === -1)
        );
      }

      return deepFreeze(Object.assign({}, state, {
        selectedFiles,
      }));
    }

    case GALLERY.DESELECT_FILES: {
      let selectedFiles = null;
      if (action.payload.ids === null) {
        // No param was passed, deselect everything.
        selectedFiles = [];
      } else {
        // We're dealing with an array of ids to deselect.
        selectedFiles = state.selectedFiles
          .filter(id => action.payload.ids.indexOf(id) === -1);
      }

      return deepFreeze(Object.assign({}, state, {
        selectedFiles,
      }));
    }

    // De-select and remove the files listed in payload.ids
    case GALLERY.DELETE_ITEM_SUCCESS: {
      return deepFreeze(Object.assign({}, state, {
        selectedFiles: state.selectedFiles.filter(id => action.payload.ids.indexOf(id) === -1),
        files: state.files.filter(file => action.payload.ids.indexOf(file.id) === -1),
        count: state.count - 1,
      }));
    }

    case GALLERY.LOAD_FOLDER_REQUEST: {
      return deepFreeze(Object.assign({}, state, {
        errorMessage: null,
        selectedFiles: [],
        loading: true,
      }));
    }

    case GALLERY.LOAD_FOLDER_SUCCESS: {
      return deepFreeze(Object.assign({}, state, {
        folder: action.payload.folder,
        files: action.payload.files,
        count: action.payload.count,
        loading: false,
      }));
    }

    case GALLERY.LOAD_FOLDER_FAILURE: {
      return deepFreeze(Object.assign({}, state, {
        errorMessage: action.payload.message,
        loading: false,
      }));
    }

    case GALLERY.ADD_FOLDER_REQUEST:
      return state;

    case GALLERY.ADD_FOLDER_FAILURE:
      return state;

    case GALLERY.ADD_FOLDER_SUCCESS:
      return state;

    default:
      return state;
  }
}
