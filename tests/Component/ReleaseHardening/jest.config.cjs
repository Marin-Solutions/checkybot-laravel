module.exports = {
  ...require('../../../mobile/node_modules/jest-expo/jest-preset'),
  rootDir: '../../..',
  testMatch: ['<rootDir>/tests/Component/ReleaseHardening/**/*.test.ts?(x)'],
  moduleNameMapper: {
    '^react$': '<rootDir>/mobile/node_modules/react',
    '^react/(.*)$': '<rootDir>/mobile/node_modules/react/$1',
    '^react-native$': '<rootDir>/mobile/node_modules/react-native',
    '^react-test-renderer$': '<rootDir>/mobile/node_modules/react-test-renderer',
    '^@testing-library/react-native$': '<rootDir>/mobile/node_modules/@testing-library/react-native',
  },
  transformIgnorePatterns: [
    'node_modules/(?!((jest-)?react-native|@react-native(-community)?|expo(nent)?|@expo(nent)?/.*|expo-modules-core|expo-router|react-native-safe-area-context|react-native-screens))',
  ],
};
